<?php

namespace App\Tests\Unit\Command;

use App\Command\ScanCommand;
use App\Entity\ScanRun;
use App\Enum\ScanRunStatus;
use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Repository\ScanRunRepository;
use App\Service\ScanService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ScanCommand::class)]
class ScanCommandTest extends TestCase
{
    private MockObject&ScanService $scanService;
    private MockObject&ScanRunRepository $scanRunRepository;
    private MockObject&FindingRepository $findingRepository;
    private MockObject&DomainRepository $domainRepository;

    protected function setUp(): void
    {
        $this->scanService = $this->createMock(ScanService::class);
        $this->scanRunRepository = $this->createMock(ScanRunRepository::class);
        $this->findingRepository = $this->createMock(FindingRepository::class);
        $this->domainRepository = $this->createMock(DomainRepository::class);
    }

    private function buildTester(): CommandTester
    {
        $command = new ScanCommand(
            $this->scanService,
            $this->scanRunRepository,
            $this->findingRepository,
            $this->domainRepository,
        );

        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('app:scan'));
    }

    public function testSkipsWhenSuccessfulRunAlreadyExistsToday(): void
    {
        $todayRun = (new ScanRun())->setStatus(ScanRunStatus::Success->value);
        $this->setEntityId($todayRun, 42);

        $this->scanRunRepository
            ->expects($this->once())
            ->method('findLatestSuccessfulToday')
            ->willReturn($todayRun);

        $this->scanService->expects($this->never())->method('runFullScan');

        $tester = $this->buildTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Überspringe', $tester->getDisplay());
    }

    public function testForceOptionRunsScanAndPrintsSummary(): void
    {
        $scanRun = (new ScanRun())
            ->setStatus(ScanRunStatus::Partial->value)
            ->setStartedAt(new \DateTimeImmutable('-1 minute'))
            ->setFinishedAt(new \DateTimeImmutable('now'));
        $this->setEntityId($scanRun, 7);

        $findingA = $this->createConfiguredMock(\App\Entity\Finding::class, ['getFindingType' => 'TLS_VERSION']);
        $findingB = $this->createConfiguredMock(\App\Entity\Finding::class, ['getFindingType' => 'TLS_VERSION']);

        $this->scanRunRepository->method('findLatestSuccessfulToday')->willReturn($scanRun);
        $this->domainRepository->method('findActive')->willReturn([
            $this->createStub(\App\Entity\Domain::class),
            $this->createStub(\App\Entity\Domain::class),
        ]);

        $this->scanService
            ->expects($this->once())
            ->method('runFullScan')
            ->willReturnCallback(function (?callable $onDomainScanned = null) use ($scanRun): ScanRun {
                $this->assertIsCallable($onDomainScanned);
                $onDomainScanned('example.org');
                $onDomainScanned('www.example.org');
                return $scanRun;
            });

        $this->findingRepository
            ->expects($this->once())
            ->method('findByRunId')
            ->with(7)
            ->willReturn([$findingA, $findingB]);

        $tester = $this->buildTester();
        $exitCode = $tester->execute(['--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Force-Option erkannt', $tester->getDisplay());
        $this->assertStringContainsString('Zusammenfassung', $tester->getDisplay());
    }

    public function testShowsWarningWhenRunHasNoFinishedAt(): void
    {
        $scanRun = new ScanRun();
        $this->setEntityId($scanRun, 13);

        $this->scanRunRepository->method('findLatestSuccessfulToday')->willReturn(null);
        $this->domainRepository->method('findActive')->willReturn([]);
        $this->scanService->method('runFullScan')->willReturn($scanRun);
        $this->findingRepository->expects($this->never())->method('findByRunId');

        $tester = $this->buildTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Keine aktiven Domains vorhanden', $tester->getDisplay());
    }

    public function testReturnsFailureWhenUnhandledExceptionOccurs(): void
    {
        $this->scanRunRepository->method('findLatestSuccessfulToday')->willReturn(null);
        $this->domainRepository
            ->method('findActive')
            ->willThrowException(new \RuntimeException('boom'));

        $tester = $this->buildTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FEHLER', $tester->getDisplay());
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionObject($entity);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
