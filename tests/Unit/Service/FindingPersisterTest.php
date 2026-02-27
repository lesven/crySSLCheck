<?php

namespace App\Tests\Unit\Service;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScanRun;
use App\Repository\FindingRepository;
use App\Service\FindingPersister;
use App\Service\MailService;
use App\ValueObject\ScanConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for FindingPersister::persistFindings() – status determination,
 * entity persistence, mail alerts, and stale finding resolution.
 *
 * Migrated from ScanServicePersistTest after extraction.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(FindingPersister::class)]
class FindingPersisterTest extends TestCase
{
    private MockObject&EntityManagerInterface $em;
    private MockObject&FindingRepository $findingRepo;
    private MockObject&MailService $mailService;
    private FindingPersister $persister;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->findingRepo = $this->createMock(FindingRepository::class);
        $this->mailService = $this->createMock(MailService::class);

        $this->persister = new FindingPersister(
            entityManager: $this->em,
            findingRepository: $this->findingRepo,
            mailService: $this->mailService,
            logger: new NullLogger(),
            config: new ScanConfiguration(
                scanTimeout: 5,
                retryDelay: 0,
                retryCount: 0,
                notifyOnUnreachable: false,
                minRsaKeyBits: 2048,
            ),
        );
    }

    private function createDomain(): Domain
    {
        $domain = new Domain();
        $domain->setFqdn('example.com');
        $domain->setPort(443);
        $ref = new \ReflectionProperty(Domain::class, 'id');
        $ref->setValue($domain, 1);
        return $domain;
    }

    private function createScanRun(): ScanRun
    {
        $scanRun = new ScanRun();
        $ref = new \ReflectionProperty(ScanRun::class, 'id');
        $ref->setValue($scanRun, 10);
        return $scanRun;
    }

    private function createPersisterWithNotify(bool $notifyOnUnreachable): FindingPersister
    {
        return new FindingPersister(
            entityManager: $this->em,
            findingRepository: $this->findingRepo,
            mailService: $this->mailService,
            logger: new NullLogger(),
            config: new ScanConfiguration(
                scanTimeout: 5,
                retryDelay: 0,
                retryCount: 0,
                notifyOnUnreachable: $notifyOnUnreachable,
                minRsaKeyBits: 2048,
            ),
        );
    }

    // ── Status determination ─────────────────────────────────────────────────

    public function testNewFindingGetsStatusNew(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $results = $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'CERT_EXPIRY',
            'severity'     => 'high',
            'details'      => ['days_remaining' => 5],
        ]]);

        $this->assertCount(1, $results);
        $this->assertSame('new', $results[0]['status']);
        $this->assertSame('CERT_EXPIRY', $results[0]['finding_type']);
    }

    public function testKnownFindingGetsStatusKnown(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(true);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $results = $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'CERT_EXPIRY',
            'severity'     => 'high',
            'details'      => ['days_remaining' => 5],
        ]]);

        $this->assertCount(1, $results);
        $this->assertSame('known', $results[0]['status']);
    }

    // ── Mail alert logic ─────────────────────────────────────────────────────

    public function testNewHighSeveritySendsMail(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $this->mailService
            ->expects($this->once())
            ->method('sendFindingAlert');

        $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'CERT_EXPIRY',
            'severity'     => 'high',
            'details'      => [],
        ]]);
    }

    public function testNewCriticalSeveritySendsMail(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $this->mailService
            ->expects($this->once())
            ->method('sendFindingAlert');

        $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'CERT_EXPIRY',
            'severity'     => 'critical',
            'details'      => [],
        ]]);
    }

    public function testKnownFindingDoesNotSendMail(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(true);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $this->mailService
            ->expects($this->never())
            ->method('sendFindingAlert');

        $this->mailService
            ->expects($this->once())
            ->method('recordSkipped');

        $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'CERT_EXPIRY',
            'severity'     => 'high',
            'details'      => [],
        ]]);
    }

    public function testNewLowSeverityDoesNotSendMail(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $this->mailService
            ->expects($this->never())
            ->method('sendFindingAlert');

        $this->mailService
            ->expects($this->once())
            ->method('recordSkipped');

        $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'CERT_EXPIRY',
            'severity'     => 'low',
            'details'      => [],
        ]]);
    }

    public function testNewMediumSeverityDoesNotSendMail(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $this->mailService
            ->expects($this->never())
            ->method('sendFindingAlert');

        $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'CERT_EXPIRY',
            'severity'     => 'medium',
            'details'      => [],
        ]]);
    }

    public function testUnreachableDoesNotSendMailWhenNotifyDisabled(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $this->mailService
            ->expects($this->never())
            ->method('sendFindingAlert');

        $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'UNREACHABLE',
            'severity'     => 'low',
            'details'      => [],
        ]]);
    }

    public function testUnreachableSendsMailWhenNotifyEnabled(): void
    {
        $persister = $this->createPersisterWithNotify(true);

        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $this->mailService
            ->expects($this->once())
            ->method('sendFindingAlert');

        $persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'UNREACHABLE',
            'severity'     => 'low',
            'details'      => [],
        ]]);
    }

    public function testErrorTypeSendsMailWhenNotifyEnabled(): void
    {
        $persister = $this->createPersisterWithNotify(true);

        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $this->mailService
            ->expects($this->once())
            ->method('sendFindingAlert');

        $persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'ERROR',
            'severity'     => 'low',
            'details'      => [],
        ]]);
    }

    // ── Entity persistence ───────────────────────────────────────────────────

    public function testPersistsOneEntityPerFinding(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $persistedEntities = [];
        $this->em->method('persist')
            ->willReturnCallback(function ($entity) use (&$persistedEntities) {
                $persistedEntities[] = $entity;
            });

        $this->persister->persistFindings($domain, $scanRun, [
            ['finding_type' => 'CERT_EXPIRY', 'severity' => 'high', 'details' => []],
            ['finding_type' => 'TLS_VERSION', 'severity' => 'high', 'details' => []],
        ]);

        $findingEntities = array_filter($persistedEntities, fn($e) => $e instanceof Finding);
        $this->assertCount(2, $findingEntities);
    }

    public function testFlushCalledOnce(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $this->em->expects($this->once())->method('flush');

        $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'OK',
            'severity'     => 'ok',
            'details'      => [],
        ]]);
    }

    // ── Resolution of stale findings ─────────────────────────────────────────

    public function testResolvesStaleFindings(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $staleFinding = new Finding();
        $staleFinding->setDomain($domain);
        $staleFinding->setScanRun($scanRun);
        $staleFinding->setFindingType('CERT_EXPIRY');
        $staleFinding->setSeverity('high');
        $staleFinding->setStatus('new');
        $staleFinding->setDetails([]);

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([$staleFinding]);

        $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'OK',
            'severity'     => 'ok',
            'details'      => [],
        ]]);

        $this->assertSame('resolved', $staleFinding->getStatus());
    }

    public function testDoesNotResolveFindingThatStillAppears(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $prevFinding = new Finding();
        $prevFinding->setDomain($domain);
        $prevFinding->setScanRun($scanRun);
        $prevFinding->setFindingType('CERT_EXPIRY');
        $prevFinding->setSeverity('high');
        $prevFinding->setStatus('new');
        $prevFinding->setDetails([]);

        $this->findingRepo->method('isKnownFinding')->willReturn(true);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([$prevFinding]);

        $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'CERT_EXPIRY',
            'severity'     => 'high',
            'details'      => [],
        ]]);

        $this->assertSame('new', $prevFinding->getStatus());
    }

    // ── Multiple findings in one scan ────────────────────────────────────────

    public function testMultipleFindingsProcessedCorrectly(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $callIndex = 0;
        $this->findingRepo->method('isKnownFinding')
            ->willReturnCallback(function () use (&$callIndex) {
                return ($callIndex++ > 0);
            });
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $results = $this->persister->persistFindings($domain, $scanRun, [
            ['finding_type' => 'CERT_EXPIRY', 'severity' => 'high', 'details' => []],
            ['finding_type' => 'TLS_VERSION', 'severity' => 'high', 'details' => []],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame('new', $results[0]['status']);
        $this->assertSame('known', $results[1]['status']);
    }

    // ── OK finding suppresses mail ───────────────────────────────────────────

    public function testOkFindingDoesNotSendMail(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $this->mailService
            ->expects($this->never())
            ->method('sendFindingAlert');

        $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'OK',
            'severity'     => 'ok',
            'details'      => [],
        ]]);
    }

    // ── Mail failure logged but does not throw ────────────────────────────────

    public function testMailFailureDoesNotThrow(): void
    {
        $domain = $this->createDomain();
        $scanRun = $this->createScanRun();

        $this->findingRepo->method('isKnownFinding')->willReturn(false);
        $this->findingRepo->method('findPreviousRunFindings')->willReturn([]);

        $this->mailService
            ->method('sendFindingAlert')
            ->willReturn(false);

        $results = $this->persister->persistFindings($domain, $scanRun, [[
            'finding_type' => 'CERT_EXPIRY',
            'severity'     => 'critical',
            'details'      => [],
        ]]);

        $this->assertCount(1, $results);
        $this->assertSame('new', $results[0]['status']);
    }
}
