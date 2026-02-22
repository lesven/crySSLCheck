<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScanRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Finding::class)]
class FindingTest extends TestCase
{
    private function createFinding(string $type = 'CERT_EXPIRY', string $severity = 'high'): Finding
    {
        $domain = new Domain();
        $domain->setFqdn('example.com');
        $domain->setPort(443);

        $scanRun = new ScanRun();

        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setScanRun($scanRun);
        $finding->setFindingType($type);
        $finding->setSeverity($severity);
        $finding->setDetails([]);

        return $finding;
    }

    public function testDefaultStatusIsNew(): void
    {
        $finding = new Finding();
        $this->assertSame('new', $finding->getStatus());
    }

    public function testDefaultDetailsIsEmptyArray(): void
    {
        $finding = new Finding();
        $this->assertSame([], $finding->getDetails());
    }

    public function testIdIsNullBeforePersist(): void
    {
        $finding = $this->createFinding();
        $this->assertNull($finding->getId());
    }

    public function testSetAndGetFindingType(): void
    {
        $finding = $this->createFinding('TLS_VERSION');
        $this->assertSame('TLS_VERSION', $finding->getFindingType());
    }

    public function testSetAndGetSeverity(): void
    {
        $finding = $this->createFinding('OK', 'ok');
        $this->assertSame('ok', $finding->getSeverity());
    }

    public function testSetAndGetDetails(): void
    {
        $finding = $this->createFinding();
        $details = ['days_remaining' => 5, 'expiry_date' => '2026-01-01 00:00:00'];
        $finding->setDetails($details);
        $this->assertSame($details, $finding->getDetails());
    }

    public function testSetAndGetStatus(): void
    {
        $finding = $this->createFinding();
        $finding->setStatus('known');
        $this->assertSame('known', $finding->getStatus());
    }

    public function testMarkResolvedSetsStatusToResolved(): void
    {
        $finding = $this->createFinding();
        $this->assertSame('new', $finding->getStatus());

        $finding->markResolved();
        $this->assertSame('resolved', $finding->getStatus());
    }

    public function testOnPrePersistSetsCheckedAt(): void
    {
        $finding = $this->createFinding();
        $this->assertNull($finding->getCheckedAt());

        $before = new \DateTimeImmutable();
        $finding->onPrePersist();
        $after = new \DateTimeImmutable();

        $this->assertNotNull($finding->getCheckedAt());
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $finding->getCheckedAt()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $finding->getCheckedAt()->getTimestamp());
    }

    public function testSetAndGetDomain(): void
    {
        $domain = new Domain();
        $domain->setFqdn('test.com');
        $domain->setPort(443);

        $finding = new Finding();
        $finding->setDomain($domain);
        $this->assertSame($domain, $finding->getDomain());
    }

    public function testSetAndGetScanRun(): void
    {
        $scanRun = new ScanRun();
        $finding = new Finding();
        $finding->setScanRun($scanRun);
        $this->assertSame($scanRun, $finding->getScanRun());
    }

    #[DataProvider('severityBadgeProvider')]
    public function testGetSeverityBadgeClass(string $severity, string $expectedClass): void
    {
        $finding = $this->createFinding('CERT_EXPIRY', $severity);
        $this->assertSame($expectedClass, $finding->getSeverityBadgeClass());
    }

    public static function severityBadgeProvider(): array
    {
        return [
            'critical maps to danger'   => ['critical', 'danger'],
            'high maps to warning'      => ['high', 'warning'],
            'medium maps to info'       => ['medium', 'info'],
            'low maps to secondary'     => ['low', 'secondary'],
            'ok maps to success'        => ['ok', 'success'],
            'unknown maps to success'   => ['unknown', 'success'],
        ];
    }

    #[DataProvider('statusBadgeProvider')]
    public function testGetStatusBadgeClass(string $status, string $expectedClass): void
    {
        $finding = $this->createFinding();
        $finding->setStatus($status);
        $this->assertSame($expectedClass, $finding->getStatusBadgeClass());
    }

    public static function statusBadgeProvider(): array
    {
        return [
            'new maps to danger'        => ['new', 'danger'],
            'known maps to warning'     => ['known', 'warning'],
            'resolved maps to success'  => ['resolved', 'success'],
            'unknown maps to secondary' => ['other', 'secondary'],
        ];
    }
}
