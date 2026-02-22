<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ScanRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScanRun::class)]
class ScanRunTest extends TestCase
{
    public function testDefaultStatusIsRunning(): void
    {
        $scanRun = new ScanRun();
        $this->assertSame('running', $scanRun->getStatus());
    }

    public function testStartedAtIsSetOnConstruction(): void
    {
        $before = new \DateTimeImmutable();
        $scanRun = new ScanRun();
        $after = new \DateTimeImmutable();

        $this->assertNotNull($scanRun->getStartedAt());
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $scanRun->getStartedAt()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $scanRun->getStartedAt()->getTimestamp());
    }

    public function testFinishedAtIsNullByDefault(): void
    {
        $scanRun = new ScanRun();
        $this->assertNull($scanRun->getFinishedAt());
    }

    public function testIdIsNullBeforePersist(): void
    {
        $scanRun = new ScanRun();
        $this->assertNull($scanRun->getId());
    }

    public function testFindingsCollectionIsEmptyByDefault(): void
    {
        $scanRun = new ScanRun();
        $this->assertCount(0, $scanRun->getFindings());
    }

    #[DataProvider('finishStatusProvider')]
    public function testFinishSetsStatusAndFinishedAt(string $status): void
    {
        $scanRun = new ScanRun();
        $before = new \DateTimeImmutable();
        $scanRun->finish($status);
        $after = new \DateTimeImmutable();

        $this->assertSame($status, $scanRun->getStatus());
        $this->assertNotNull($scanRun->getFinishedAt());
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $scanRun->getFinishedAt()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $scanRun->getFinishedAt()->getTimestamp());
    }

    public static function finishStatusProvider(): array
    {
        return [
            'success' => ['success'],
            'partial' => ['partial'],
            'failed'  => ['failed'],
        ];
    }

    public function testSetAndGetStatus(): void
    {
        $scanRun = new ScanRun();
        $scanRun->setStatus('partial');
        $this->assertSame('partial', $scanRun->getStatus());
    }

    public function testSetAndGetStartedAt(): void
    {
        $scanRun = new ScanRun();
        $dt = new \DateTimeImmutable('2025-01-15 10:00:00');
        $scanRun->setStartedAt($dt);
        $this->assertSame($dt, $scanRun->getStartedAt());
    }

    public function testSetAndGetFinishedAt(): void
    {
        $scanRun = new ScanRun();
        $dt = new \DateTimeImmutable('2025-01-15 10:05:00');
        $scanRun->setFinishedAt($dt);
        $this->assertSame($dt, $scanRun->getFinishedAt());
    }

    public function testSetFinishedAtToNull(): void
    {
        $scanRun = new ScanRun();
        $scanRun->setFinishedAt(new \DateTimeImmutable());
        $scanRun->setFinishedAt(null);
        $this->assertNull($scanRun->getFinishedAt());
    }

    public function testFinishOverwritesPreviousFinishedAt(): void
    {
        $scanRun = new ScanRun();
        $scanRun->finish('success');
        $firstFinish = $scanRun->getFinishedAt();

        $scanRun->finish('partial');
        $secondFinish = $scanRun->getFinishedAt();

        $this->assertGreaterThanOrEqual($firstFinish->getTimestamp(), $secondFinish->getTimestamp());
    }
}
