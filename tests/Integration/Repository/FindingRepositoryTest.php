<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScanRun;
use App\Enum\FindingStatus;
use App\Enum\FindingType;
use App\Enum\Severity;
use App\Repository\FindingRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FindingRepository::class)]
class FindingRepositoryTest extends IntegrationTestCase
{
    private FindingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(FindingRepository::class);
    }

    private function createDomain(string $fqdn = 'example.com', int $port = 443): Domain
    {
        $domain = new Domain();
        $domain->setFqdn($fqdn);
        $domain->setPort($port);
        $this->em->persist($domain);

        return $domain;
    }

    private function createScanRun(string $status = 'success'): ScanRun
    {
        $scanRun = new ScanRun();
        $scanRun->finish($status);
        $this->em->persist($scanRun);

        return $scanRun;
    }

    private function createFinding(
        Domain $domain,
        ScanRun $scanRun,
        FindingType $type = FindingType::CERT_EXPIRY,
        Severity $severity = Severity::HIGH,
        FindingStatus $status = FindingStatus::NEW,
        array $details = [],
    ): Finding {
        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setScanRun($scanRun);
        $finding->setFindingType($type);
        $finding->setSeverity($severity);
        $finding->setStatus($status);
        $finding->setDetails($details);
        $this->em->persist($finding);

        return $finding;
    }

    // ── findPaginated ─────────────────────────────────────────────────────────

    public function testFindPaginatedReturnsEmptyArrayWhenNoFindings(): void
    {
        $result = $this->repository->findPaginated(10, 0);
        $this->assertSame([], $result);
    }

    public function testFindPaginatedReturnsAllFindings(): void
    {
        $domain  = $this->createDomain();
        $scanRun = $this->createScanRun();
        $this->createFinding($domain, $scanRun, FindingType::CERT_EXPIRY);
        $this->createFinding($domain, $scanRun, FindingType::TLS_VERSION);
        $this->em->flush();

        $result = $this->repository->findPaginated(10, 0);
        $this->assertCount(2, $result);
    }

    public function testFindPaginatedRespectsLimit(): void
    {
        $domain  = $this->createDomain();
        $scanRun = $this->createScanRun();
        for ($i = 0; $i < 5; $i++) {
            $this->createFinding($domain, $scanRun, FindingType::CERT_EXPIRY);
        }
        $this->em->flush();

        $result = $this->repository->findPaginated(3, 0);
        $this->assertCount(3, $result);
    }

    public function testFindPaginatedRespectsOffset(): void
    {
        $domain  = $this->createDomain();
        $scanRun = $this->createScanRun();
        for ($i = 0; $i < 5; $i++) {
            $this->createFinding($domain, $scanRun, FindingType::CERT_EXPIRY);
        }
        $this->em->flush();

        $page1 = $this->repository->findPaginated(3, 0);
        $page2 = $this->repository->findPaginated(3, 3);

        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    public function testFindPaginatedFiltersOutOkFindingsWhenProblemsOnly(): void
    {
        $domain  = $this->createDomain();
        $scanRun = $this->createScanRun();
        $this->createFinding($domain, $scanRun, FindingType::OK, Severity::OK);
        $this->createFinding($domain, $scanRun, FindingType::CERT_EXPIRY, Severity::HIGH);
        $this->em->flush();

        $result = $this->repository->findPaginated(10, 0, problemsOnly: true);
        $this->assertCount(1, $result);
        $this->assertSame(FindingType::CERT_EXPIRY, $result[0]->getFindingType());
    }

    public function testFindPaginatedFiltersByRunId(): void
    {
        $domain   = $this->createDomain();
        $scanRun1 = $this->createScanRun();
        $scanRun2 = $this->createScanRun();
        $this->createFinding($domain, $scanRun1, FindingType::CERT_EXPIRY);
        $this->createFinding($domain, $scanRun2, FindingType::TLS_VERSION);
        $this->em->flush();

        $result = $this->repository->findPaginated(10, 0, runId: $scanRun1->getId());
        $this->assertCount(1, $result);
        $this->assertSame(FindingType::CERT_EXPIRY, $result[0]->getFindingType());
    }

    // ── countFiltered ─────────────────────────────────────────────────────────

    public function testCountFilteredReturnsZeroWhenNoFindings(): void
    {
        $this->assertSame(0, $this->repository->countFiltered());
    }

    public function testCountFilteredReturnsCorrectCount(): void
    {
        $domain  = $this->createDomain();
        $scanRun = $this->createScanRun();
        $this->createFinding($domain, $scanRun, FindingType::CERT_EXPIRY);
        $this->createFinding($domain, $scanRun, FindingType::TLS_VERSION);
        $this->em->flush();

        $this->assertSame(2, $this->repository->countFiltered());
    }

    public function testCountFilteredExcludesOkFindingsWhenProblemsOnly(): void
    {
        $domain  = $this->createDomain();
        $scanRun = $this->createScanRun();
        $this->createFinding($domain, $scanRun, FindingType::OK, Severity::OK);
        $this->createFinding($domain, $scanRun, FindingType::CERT_EXPIRY, Severity::HIGH);
        $this->em->flush();

        $this->assertSame(1, $this->repository->countFiltered(problemsOnly: true));
    }

    public function testCountFilteredByRunId(): void
    {
        $domain   = $this->createDomain();
        $scanRun1 = $this->createScanRun();
        $scanRun2 = $this->createScanRun();
        $this->createFinding($domain, $scanRun1, FindingType::CERT_EXPIRY);
        $this->createFinding($domain, $scanRun1, FindingType::TLS_VERSION);
        $this->createFinding($domain, $scanRun2, FindingType::CHAIN_ERROR);
        $this->em->flush();

        $this->assertSame(2, $this->repository->countFiltered(runId: $scanRun1->getId()));
        $this->assertSame(1, $this->repository->countFiltered(runId: $scanRun2->getId()));
    }

    // ── isKnownFinding ────────────────────────────────────────────────────────

    public function testIsKnownFindingReturnsFalseWhenNoPreviousFindings(): void
    {
        $domain  = $this->createDomain();
        $scanRun = $this->createScanRun();
        $this->em->flush();

        $isKnown = $this->repository->isKnownFinding($domain->getId(), FindingType::CERT_EXPIRY, $scanRun->getId());
        $this->assertFalse($isKnown);
    }

    public function testIsKnownFindingReturnsTrueWhenSameTypeExistsInPreviousRun(): void
    {
        $domain   = $this->createDomain();
        $scanRun1 = $this->createScanRun();
        $scanRun2 = $this->createScanRun();
        $this->createFinding($domain, $scanRun1, FindingType::CERT_EXPIRY, Severity::HIGH, FindingStatus::NEW);
        $this->em->flush();

        $isKnown = $this->repository->isKnownFinding($domain->getId(), FindingType::CERT_EXPIRY, $scanRun2->getId());
        $this->assertTrue($isKnown);
    }

    public function testIsKnownFindingReturnsFalseForDifferentFindingType(): void
    {
        $domain   = $this->createDomain();
        $scanRun1 = $this->createScanRun();
        $scanRun2 = $this->createScanRun();
        $this->createFinding($domain, $scanRun1, FindingType::CERT_EXPIRY, Severity::HIGH, FindingStatus::NEW);
        $this->em->flush();

        $isKnown = $this->repository->isKnownFinding($domain->getId(), FindingType::TLS_VERSION, $scanRun2->getId());
        $this->assertFalse($isKnown);
    }

    public function testIsKnownFindingReturnsFalseForResolvedPreviousFinding(): void
    {
        $domain   = $this->createDomain();
        $scanRun1 = $this->createScanRun();
        $scanRun2 = $this->createScanRun();
        $this->createFinding($domain, $scanRun1, FindingType::CERT_EXPIRY, Severity::HIGH, FindingStatus::RESOLVED);
        $this->em->flush();

        $isKnown = $this->repository->isKnownFinding($domain->getId(), FindingType::CERT_EXPIRY, $scanRun2->getId());
        $this->assertFalse($isKnown);
    }

    public function testIsKnownFindingReturnsTrueForKnownStatusInPreviousRun(): void
    {
        $domain   = $this->createDomain();
        $scanRun1 = $this->createScanRun();
        $scanRun2 = $this->createScanRun();
        $this->createFinding($domain, $scanRun1, FindingType::TLS_VERSION, Severity::HIGH, FindingStatus::KNOWN);
        $this->em->flush();

        $isKnown = $this->repository->isKnownFinding($domain->getId(), FindingType::TLS_VERSION, $scanRun2->getId());
        $this->assertTrue($isKnown);
    }

    public function testIsKnownFindingExcludesCurrentRun(): void
    {
        $domain  = $this->createDomain();
        $scanRun = $this->createScanRun();
        $this->createFinding($domain, $scanRun, FindingType::CERT_EXPIRY, Severity::HIGH, FindingStatus::NEW);
        $this->em->flush();

        // Checking within the same run – should be false (not looking at current run)
        $isKnown = $this->repository->isKnownFinding($domain->getId(), FindingType::CERT_EXPIRY, $scanRun->getId());
        $this->assertFalse($isKnown);
    }

    // ── findPreviousRunFindings ───────────────────────────────────────────────

    public function testFindPreviousRunFindingsReturnsEmptyWhenNoHistory(): void
    {
        $domain  = $this->createDomain();
        $scanRun = $this->createScanRun();
        $this->em->flush();

        $result = $this->repository->findPreviousRunFindings($domain->getId(), $scanRun->getId());
        $this->assertSame([], $result);
    }

    public function testFindPreviousRunFindingsReturnsOlderUnresolvedFindings(): void
    {
        $domain   = $this->createDomain();
        $scanRun1 = $this->createScanRun();
        $scanRun2 = $this->createScanRun();
        $this->createFinding($domain, $scanRun1, FindingType::CERT_EXPIRY, Severity::HIGH, FindingStatus::NEW);
        $this->em->flush();

        $result = $this->repository->findPreviousRunFindings($domain->getId(), $scanRun2->getId());
        $this->assertCount(1, $result);
        $this->assertSame(FindingType::CERT_EXPIRY, $result[0]->getFindingType());
    }

    public function testFindPreviousRunFindingsExcludesResolvedFindings(): void
    {
        $domain   = $this->createDomain();
        $scanRun1 = $this->createScanRun();
        $scanRun2 = $this->createScanRun();
        $this->createFinding($domain, $scanRun1, FindingType::CERT_EXPIRY, Severity::HIGH, FindingStatus::RESOLVED);
        $this->em->flush();

        $result = $this->repository->findPreviousRunFindings($domain->getId(), $scanRun2->getId());
        $this->assertSame([], $result);
    }

    public function testFindPreviousRunFindingsExcludesOkFindings(): void
    {
        $domain   = $this->createDomain();
        $scanRun1 = $this->createScanRun();
        $scanRun2 = $this->createScanRun();
        $this->createFinding($domain, $scanRun1, FindingType::OK, Severity::OK, FindingStatus::NEW);
        $this->em->flush();

        $result = $this->repository->findPreviousRunFindings($domain->getId(), $scanRun2->getId());
        $this->assertSame([], $result);
    }

    public function testFindPreviousRunFindingsExcludesCurrentRun(): void
    {
        $domain  = $this->createDomain();
        $scanRun = $this->createScanRun();
        $this->createFinding($domain, $scanRun, FindingType::CERT_EXPIRY, Severity::HIGH, FindingStatus::NEW);
        $this->em->flush();

        $result = $this->repository->findPreviousRunFindings($domain->getId(), $scanRun->getId());
        $this->assertSame([], $result);
    }

    // ── findLatestRunId ──────────────────────────────────────────────────────

    public function testFindLatestRunIdReturnsNullWhenNoFindings(): void
    {
        $result = $this->repository->findLatestRunId();
        $this->assertNull($result);
    }

    public function testFindLatestRunIdReturnsIdOfMostRecentRun(): void
    {
        $domain   = $this->createDomain();
        $scanRun1 = $this->createScanRun();
        // Small sleep to ensure different finishedAt timestamps
        $scanRun2 = new ScanRun();
        $scanRun2->setFinishedAt(new \DateTimeImmutable('+1 second'));
        $scanRun2->setStatus('success');
        $this->em->persist($scanRun2);

        $this->createFinding($domain, $scanRun1, FindingType::CERT_EXPIRY);
        $this->createFinding($domain, $scanRun2, FindingType::TLS_VERSION);
        $this->em->flush();

        $latestRunId = $this->repository->findLatestRunId();
        $this->assertSame($scanRun2->getId(), $latestRunId);
    }

    // ── findByRunId ──────────────────────────────────────────────────────────

    public function testFindByRunIdReturnsEmptyArrayForUnknownRun(): void
    {
        $result = $this->repository->findByRunId(9999);
        $this->assertSame([], $result);
    }

    public function testFindByRunIdReturnsFindingsForSpecificRun(): void
    {
        $domain   = $this->createDomain();
        $scanRun1 = $this->createScanRun();
        $scanRun2 = $this->createScanRun();
        $this->createFinding($domain, $scanRun1, FindingType::CERT_EXPIRY);
        $this->createFinding($domain, $scanRun2, FindingType::TLS_VERSION);
        $this->em->flush();

        $result = $this->repository->findByRunId($scanRun1->getId());
        $this->assertCount(1, $result);
        $this->assertSame(FindingType::CERT_EXPIRY, $result[0]->getFindingType());
    }
}
