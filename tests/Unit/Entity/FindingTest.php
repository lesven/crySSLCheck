<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScanRun;
use App\Enum\FindingStatus;
use App\Enum\FindingType;
use App\Enum\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Finding::class)]
class FindingTest extends TestCase
{
    private function createFinding(FindingType $type = FindingType::CERT_EXPIRY, Severity $severity = Severity::HIGH): Finding
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
        $this->assertSame(FindingStatus::NEW, $finding->getStatus());
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
        $finding = $this->createFinding(FindingType::TLS_VERSION);
        $this->assertSame(FindingType::TLS_VERSION, $finding->getFindingType());
    }

    public function testSetAndGetSeverity(): void
    {
        $finding = $this->createFinding(FindingType::OK, Severity::OK);
        $this->assertSame(Severity::OK, $finding->getSeverity());
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
        $finding->setStatus(FindingStatus::KNOWN);
        $this->assertSame(FindingStatus::KNOWN, $finding->getStatus());
    }

    public function testMarkResolvedSetsStatusToResolved(): void
    {
        $finding = $this->createFinding();
        $this->assertSame(FindingStatus::NEW, $finding->getStatus());

        $finding->markResolved();
        $this->assertSame(FindingStatus::RESOLVED, $finding->getStatus());
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
    public function testGetSeverityBadgeClass(Severity $severity, string $expectedClass): void
    {
        $finding = $this->createFinding(FindingType::CERT_EXPIRY, $severity);
        $this->assertSame($expectedClass, $finding->getSeverityBadgeClass());
    }

    public static function severityBadgeProvider(): array
    {
        return [
            'critical maps to danger'   => [Severity::CRITICAL, 'danger'],
            'high maps to warning'      => [Severity::HIGH, 'warning'],
            'medium maps to info'       => [Severity::MEDIUM, 'info'],
            'low maps to secondary'     => [Severity::LOW, 'secondary'],
            'ok maps to success'        => [Severity::OK, 'success'],
        ];
    }

    #[DataProvider('statusBadgeProvider')]
    public function testGetStatusBadgeClass(FindingStatus $status, string $expectedClass): void
    {
        $finding = $this->createFinding();
        $finding->setStatus($status);
        $this->assertSame($expectedClass, $finding->getStatusBadgeClass());
    }

    public static function statusBadgeProvider(): array
    {
        return [
            'new maps to danger'        => [FindingStatus::NEW, 'danger'],
            'known maps to warning'     => [FindingStatus::KNOWN, 'warning'],
            'resolved maps to success'  => [FindingStatus::RESOLVED, 'success'],
        ];
    }
}
