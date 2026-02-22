<?php

namespace App\Tests\Integration\Repository;

use App\Entity\ScanRun;
use App\Repository\ScanRunRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ScanRunRepository::class)]
class ScanRunRepositoryTest extends IntegrationTestCase
{
    private ScanRunRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(ScanRunRepository::class);
    }

    private function persistScanRun(string $status, ?\DateTimeImmutable $finishedAt = null): ScanRun
    {
        $scanRun = new ScanRun();
        if ($finishedAt !== null) {
            $scanRun->finish($status);
            $scanRun->setFinishedAt($finishedAt);
        }
        $this->em->persist($scanRun);

        return $scanRun;
    }

    // ── findLatestFinished ────────────────────────────────────────────────────

    public function testFindLatestFinishedReturnsNullWhenNoScanRuns(): void
    {
        $result = $this->repository->findLatestFinished();
        $this->assertNull($result);
    }

    public function testFindLatestFinishedReturnsNullWhenOnlyRunningScanExists(): void
    {
        $scanRun = new ScanRun();
        $this->em->persist($scanRun);
        $this->em->flush();

        $result = $this->repository->findLatestFinished();
        $this->assertNull($result);
    }

    public function testFindLatestFinishedReturnsMostRecentFinishedRun(): void
    {
        $older = $this->persistScanRun('success', new \DateTimeImmutable('2025-01-01 10:00:00'));
        $newer = $this->persistScanRun('success', new \DateTimeImmutable('2025-06-01 10:00:00'));
        $this->em->flush();

        $result = $this->repository->findLatestFinished();
        $this->assertNotNull($result);
        $this->assertSame($newer->getId(), $result->getId());
    }

    public function testFindLatestFinishedIgnoresRunningScans(): void
    {
        $finished = $this->persistScanRun('success', new \DateTimeImmutable('2025-01-01 10:00:00'));
        // running scan (no finishedAt)
        $running = new ScanRun();
        $this->em->persist($running);
        $this->em->flush();

        $result = $this->repository->findLatestFinished();
        $this->assertSame($finished->getId(), $result->getId());
    }

    public function testFindLatestFinishedWorksForFailedStatus(): void
    {
        $failed = $this->persistScanRun('failed', new \DateTimeImmutable('2025-03-15 08:00:00'));
        $this->em->flush();

        $result = $this->repository->findLatestFinished();
        $this->assertNotNull($result);
        $this->assertSame('failed', $result->getStatus());
    }

    // ── findLatestSuccessfulToday ─────────────────────────────────────────────

    public function testFindLatestSuccessfulTodayReturnsNullWhenNoRuns(): void
    {
        $result = $this->repository->findLatestSuccessfulToday();
        $this->assertNull($result);
    }

    public function testFindLatestSuccessfulTodayReturnsTodaysScan(): void
    {
        $scanRun = new ScanRun();
        $scanRun->setStartedAt(new \DateTimeImmutable('today'));
        $scanRun->finish('success');
        $this->em->persist($scanRun);
        $this->em->flush();

        $result = $this->repository->findLatestSuccessfulToday();
        $this->assertNotNull($result);
        $this->assertSame($scanRun->getId(), $result->getId());
    }

    public function testFindLatestSuccessfulTodayIgnoresYesterdaysScan(): void
    {
        $scanRun = new ScanRun();
        $scanRun->setStartedAt(new \DateTimeImmutable('yesterday'));
        $scanRun->finish('success');
        $this->em->persist($scanRun);
        $this->em->flush();

        $result = $this->repository->findLatestSuccessfulToday();
        $this->assertNull($result);
    }

    public function testFindLatestSuccessfulTodayIgnoresRunningScan(): void
    {
        $running = new ScanRun();
        $running->setStartedAt(new \DateTimeImmutable('today'));
        // status stays 'running'
        $this->em->persist($running);
        $this->em->flush();

        $result = $this->repository->findLatestSuccessfulToday();
        $this->assertNull($result);
    }

    public function testFindLatestSuccessfulTodayIncludesPartialStatus(): void
    {
        $scanRun = new ScanRun();
        $scanRun->setStartedAt(new \DateTimeImmutable('today'));
        $scanRun->finish('partial');
        $this->em->persist($scanRun);
        $this->em->flush();

        $result = $this->repository->findLatestSuccessfulToday();
        $this->assertNotNull($result);
        $this->assertSame('partial', $result->getStatus());
    }

    public function testFindLatestSuccessfulTodayIncludesFailedStatus(): void
    {
        $scanRun = new ScanRun();
        $scanRun->setStartedAt(new \DateTimeImmutable('today'));
        $scanRun->finish('failed');
        $this->em->persist($scanRun);
        $this->em->flush();

        $result = $this->repository->findLatestSuccessfulToday();
        $this->assertNotNull($result);
        $this->assertSame('failed', $result->getStatus());
    }
}
