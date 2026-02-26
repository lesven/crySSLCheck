<?php

namespace App\Tests\Unit\Service;

use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Repository\ScanRunRepository;
use App\Service\CertificateAnalyzer;
use App\Service\FindingPersister;
use App\Service\ScanService;
use App\Service\TlsConnectorInterface;
use App\ValueObject\ScanConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ScanService behavior that is NOT analysis logic
 * (analysis tests moved to CertificateAnalyzerTest).
 */
#[CoversClass(ScanService::class)]
class ScanServiceAnalysisTest extends TestCase
{
    private ScanService $service;

    protected function setUp(): void
    {
        $config = new ScanConfiguration(
            scanTimeout: 5,
            retryDelay: 0,
            retryCount: 0,
            notifyOnUnreachable: false,
            minRsaKeyBits: 2048,
        );

        $this->service = new ScanService(
            entityManager: $this->createStub(EntityManagerInterface::class),
            domainRepository: $this->createStub(DomainRepository::class),
            scanRunRepository: $this->createStub(ScanRunRepository::class),
            certificateAnalyzer: new CertificateAnalyzer($config),
            tlsConnector: $this->createStub(TlsConnectorInterface::class),
            findingPersister: $this->createStub(FindingPersister::class),
            logger: new NullLogger(),
            config: $config,
        );
    }

    // ── runSingleScan: disabled domain throws exception ───────────────────────

    public function testRunSingleScanThrowsExceptionForInactiveDomain(): void
    {
        $domain = new Domain();
        $domain->setFqdn('example.com');
        $domain->setPort(443);
        $domain->setStatus('inactive');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Deaktivierte/');

        $this->service->runSingleScan($domain);
    }
}
