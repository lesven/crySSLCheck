<?php

namespace App\Service;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScanRun;
use App\Enum\FindingStatus;
use App\Enum\FindingType;
use App\Enum\ScanRunStatus;
use App\Enum\Severity;
use App\Repository\DomainRepository;
use App\Repository\ScanRunRepository;
use App\ValueObject\ScanConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ScanService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DomainRepository $domainRepository,
        private readonly ScanRunRepository $scanRunRepository,
        private readonly CertificateAnalyzer $certificateAnalyzer,
        private readonly TlsConnectorInterface $tlsConnector,
        private readonly FindingPersister $findingPersister,
        private readonly ParallelScanner $parallelScanner,
        private readonly LoggerInterface $logger,
        private readonly ScanConfiguration $config = new ScanConfiguration(),
    ) {
    }

    /**
     * @param callable(string $fqdn): void|null $onProgress Called after each domain is processed.
     */
    public function runFullScan(?callable $onProgress = null): ScanRun
    {
        $domains = $this->domainRepository->findActive();

        $scanRun = new ScanRun();
        $this->entityManager->persist($scanRun);
        $this->entityManager->flush();

        if (empty($domains)) {
            $this->logger->info('Keine aktiven Domains zu scannen.');
            $scanRun->finish(ScanRunStatus::Success->value);
            $this->entityManager->flush();
            return $scanRun;
        }

        $this->logger->info("Scan-Run #{$scanRun->getId()} gestartet mit " . count($domains) . ' Domains.');

        $hasErrors = false;
        $allFailed = true;

        if ($this->config->scanConcurrency > 1) {
            // Parallel scan via subprocess workers
            $scanResults = $this->parallelScanner->scan($domains, $onProgress);

            foreach ($scanResults as $result) {
                $domain = $result['domain'];

                if ($result['error'] !== null) {
                    $this->logger->error("Fehler beim Scannen von {$domain->getFqdn()}:{$domain->getPort()}: " . $result['error']);
                    $hasErrors = true;

                    $finding = new Finding();
                    $finding->setDomain($domain);
                    $finding->setScanRun($scanRun);
                    $finding->setFindingType(FindingType::Error->value);
                    $finding->setSeverity(Severity::Low->value);
                    $finding->setDetails(['error' => $result['error']]);
                    $finding->setStatus(FindingStatus::New->value);
                    $this->entityManager->persist($finding);
                    $this->entityManager->flush();
                    continue;
                }

                try {
                    $scanFindings = $result['findings'];
                    $this->findingPersister->persistFindings($domain, $scanRun, $scanFindings);

                    $allFailed = false;
                    foreach ($scanFindings as $f) {
                        if (in_array($f['finding_type'], [FindingType::Unreachable->value, FindingType::Error->value])) {
                            $hasErrors = true;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->error("Fehler beim Persistieren für {$domain->getFqdn()}:{$domain->getPort()}: " . $e->getMessage());
                    $hasErrors = true;
                }
            }
        } else {
            // Sequential scan (concurrency = 1, original behavior)
            foreach ($domains as $domain) {
                try {
                    $scanFindings = $this->scanDomain($domain);
                    $this->findingPersister->persistFindings($domain, $scanRun, $scanFindings);

                    $allFailed = false;
                    foreach ($scanFindings as $f) {
                        if (in_array($f['finding_type'], [FindingType::Unreachable->value, FindingType::Error->value])) {
                            $hasErrors = true;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->error("Fehler beim Scannen von {$domain->getFqdn()}:{$domain->getPort()}: " . $e->getMessage());
                    $hasErrors = true;

                    $finding = new Finding();
                    $finding->setDomain($domain);
                    $finding->setScanRun($scanRun);
                    $finding->setFindingType(FindingType::Error->value);
                    $finding->setSeverity(Severity::Low->value);
                    $finding->setDetails(['error' => $e->getMessage()]);
                    $finding->setStatus(FindingStatus::New->value);
                    $this->entityManager->persist($finding);
                    $this->entityManager->flush();
                }

                if ($onProgress !== null) {
                    $onProgress($domain->getFqdn() . ':' . $domain->getPort());
                }
            }
        }

        $status = $allFailed ? ScanRunStatus::Failed->value : ($hasErrors ? ScanRunStatus::Partial->value : ScanRunStatus::Success->value);
        $scanRun->finish($status);
        $this->entityManager->flush();

        $this->logger->info("Scan-Run #{$scanRun->getId()} beendet mit Status: {$status}");

        return $scanRun;
    }

    public function runSingleScan(Domain $domain): array
    {
        if (!$domain->isActive()) {
            throw new \RuntimeException('Deaktivierte Domains können nicht gescannt werden.');
        }

        $scanRun = new ScanRun();
        $this->entityManager->persist($scanRun);
        $this->entityManager->flush();

        $scanFindings = $this->scanDomain($domain);
        $findings = $this->findingPersister->persistFindings($domain, $scanRun, $scanFindings);

        $hasErrors = false;
        foreach ($scanFindings as $f) {
            if (in_array($f['finding_type'], [FindingType::Unreachable->value, FindingType::Error->value])) {
                $hasErrors = true;
            }
        }

        $scanRun->finish($hasErrors ? ScanRunStatus::Partial->value : ScanRunStatus::Success->value);
        $this->entityManager->flush();

        return $findings;
    }

    /**
     * @return array<array{finding_type: string, severity: string, details: array}>
     */
    public function scanDomain(Domain $domain): array
    {
        return $this->scanDomainByFqdn($domain->getFqdn(), $domain->getPort());
    }

    /**
     * Scans a domain by FQDN and port without requiring a Domain entity.
     * Used by ScanDomainCommand for parallel subprocess execution.
     *
     * @return array<int, array{finding_type: string, severity: string, details: array<string, mixed>}>
     */
    public function scanDomainByFqdn(string $fqdn, int $port): array
    {
        $result = $this->tlsConnector->connect($fqdn, $port, $this->config->scanTimeout);

        if ($result === null) {
            for ($retry = 1; $retry <= $this->config->retryCount; $retry++) {
                $this->logger->info("Retry {$retry}/{$this->config->retryCount} für {$fqdn}:{$port}");
                sleep($this->config->retryDelay);
                $result = $this->tlsConnector->connect($fqdn, $port, $this->config->scanTimeout);
                if ($result !== null) {
                    break;
                }
            }
        }

        if (is_array($result) && isset($result['connection_refused'])) {
            $this->logger->info("Connection refused für {$fqdn}:{$port} – warte {$this->config->connectionRefusedRetryDelay1}s (Versuch 2/3)");
            sleep($this->config->connectionRefusedRetryDelay1);
            $result = $this->tlsConnector->connect($fqdn, $port, $this->config->scanTimeout);

            if (is_array($result) && isset($result['connection_refused'])) {
                $this->logger->info("Connection refused für {$fqdn}:{$port} – warte {$this->config->connectionRefusedRetryDelay2}s (Versuch 3/3)");
                sleep($this->config->connectionRefusedRetryDelay2);
                $result = $this->tlsConnector->connect($fqdn, $port, $this->config->scanTimeout);
            }
        }

        if ($result === null) {
            return [[
                'finding_type' => FindingType::Unreachable->value,
                'severity'     => Severity::Low->value,
                'details'      => ['error' => "Host {$fqdn}:{$port} nicht erreichbar (Timeout)"],
            ]];
        }

        if (isset($result['error'])) {
            return [[
                'finding_type' => FindingType::Error->value,
                'severity'     => Severity::Low->value,
                'details'      => ['error' => $result['error']],
            ]];
        }

        return $this->certificateAnalyzer->analyze($result);
    }
}
