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
        private readonly LoggerInterface $logger,
        private readonly ScanConfiguration $config = new ScanConfiguration(),
    ) {
    }

    public function runFullScan(): ScanRun
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

        $this->logger->info("Scan-Run #{$scanRun->getId()} gestartet mit " . count($domains) . " Domains.");

        $hasErrors = false;
        $allFailed = true;

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
        $fqdn = $domain->getFqdn();
        $port = $domain->getPort();

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
