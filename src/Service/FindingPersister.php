<?php

namespace App\Service;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScanRun;
use App\Enum\FindingStatus;
use App\Enum\FindingType;
use App\Enum\Severity;
use App\Repository\FindingRepository;
use App\ValueObject\ScanConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Responsible for persisting scan findings, determining new/known status,
 * triggering mail alerts, and resolving stale findings.
 *
 * Extracted from ScanService to isolate persistence + notification logic.
 */
class FindingPersister
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FindingRepository $findingRepository,
        private readonly MailService $mailService,
        private readonly LoggerInterface $logger,
        private readonly ScanConfiguration $config = new ScanConfiguration(),
    ) {
    }

    /**
     * @param array<array{finding_type: string, severity: string, details: array}> $scanFindings
     * @return array<array{finding_type: string, severity: string, details: array, id: int, status: string}>
     */
    public function persistFindings(Domain $domain, ScanRun $scanRun, array $scanFindings): array
    {
        $results = [];

        foreach ($scanFindings as $rawFinding) {
            $isKnown = $this->findingRepository->isKnownFinding(
                $domain->getId(),
                $rawFinding['finding_type'],
                $scanRun->getId()
            );
            $status = $isKnown ? FindingStatus::Known->value : FindingStatus::New->value;

            $finding = new Finding();
            $finding->setDomain($domain);
            $finding->setScanRun($scanRun);
            $finding->setFindingType($rawFinding['finding_type']);
            $finding->setSeverity($rawFinding['severity']);
            $finding->setDetails($rawFinding['details']);
            $finding->setStatus($status);
            $this->entityManager->persist($finding);

            $debugSubject = sprintf('[TLS Monitor] %s – %s für %s:%d',
                $rawFinding['severity'],
                $rawFinding['finding_type'],
                $domain->getFqdn(),
                $domain->getPort(),
            );

            $isUnreachableType = in_array($rawFinding['finding_type'], [FindingType::Unreachable->value, FindingType::Error->value]);
            $severityQualifies = in_array($rawFinding['severity'], [Severity::High->value, Severity::Critical->value])
                || ($isUnreachableType && $this->config->notifyOnUnreachable);

            if ($status !== FindingStatus::New->value) {
                $this->mailService->recordSkipped($debugSubject, 'Finding bereits bekannt (status: ' . $status . ')');
            } elseif ($isUnreachableType && !$this->config->notifyOnUnreachable) {
                $this->mailService->recordSkipped($debugSubject, 'Typ ' . $rawFinding['finding_type'] . ' – Benachrichtigung deaktiviert (SCAN_NOTIFY_UNREACHABLE=false)');
            } elseif (!$severityQualifies) {
                $this->mailService->recordSkipped($debugSubject, 'Severity zu niedrig (' . $rawFinding['severity'] . ')');
            } else {
                $sent = $this->mailService->sendFindingAlert($domain, $finding);
                if (!$sent) {
                    $this->logger->warning('FindingPersister: SMTP-Alarm-Mail fehlgeschlagen', [
                        'domain'   => $domain->getFqdn(),
                        'port'     => $domain->getPort(),
                        'finding'  => $rawFinding['finding_type'],
                        'severity' => $rawFinding['severity'],
                    ]);
                }
            }

            $results[] = array_merge($rawFinding, ['status' => $status]);
        }

        // Resolve previous findings that no longer appear
        $currentFindingTypes = array_column($scanFindings, 'finding_type');
        $previousFindings = $this->findingRepository->findPreviousRunFindings($domain->getId(), $scanRun->getId());

        foreach ($previousFindings as $prevFinding) {
            if (!in_array($prevFinding->getFindingType(), $currentFindingTypes)) {
                $prevFinding->markResolved();
            }
        }

        $this->entityManager->flush();

        return $results;
    }
}
