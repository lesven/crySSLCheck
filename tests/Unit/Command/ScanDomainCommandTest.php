<?php

namespace App\Tests\Unit\Command;

use App\Command\ScanDomainCommand;
use App\Entity\Domain;
use App\Entity\ScanRun;
use App\Repository\DomainRepository;
use App\Repository\ScanRunRepository;
use App\Service\ScanService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ScanDomainCommand::class)]
class ScanDomainCommandTest extends TestCase
{
    public function testReturnsTwoWhenDomainOrScanRunMissing(): void
    {
        $domainRepository = $this->createMock(DomainRepository::class);
        $scanRunRepository = $this->createMock(ScanRunRepository::class);
        $scanService = $this->createMock(ScanService::class);

        $domainRepository->method('find')->willReturn(null);
        $scanRunRepository->method('find')->willReturn(null);

        $command = new ScanDomainCommand($domainRepository, $scanRunRepository, $scanService);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'domain-id' => '1',
            'scan-run-id' => '2',
        ]);

        $this->assertSame(2, $exitCode);
    }

    public function testReturnsScanServiceExitCode(): void
    {
        $domainRepository = $this->createMock(DomainRepository::class);
        $scanRunRepository = $this->createMock(ScanRunRepository::class);
        $scanService = $this->createMock(ScanService::class);

        $domain = new Domain();
        $scanRun = new ScanRun();

        $domainRepository->method('find')->with(10)->willReturn($domain);
        $scanRunRepository->method('find')->with(77)->willReturn($scanRun);
        $scanService->method('scanAndPersistDomain')->with($domain, $scanRun)->willReturn(1);

        $command = new ScanDomainCommand($domainRepository, $scanRunRepository, $scanService);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'domain-id' => '10',
            'scan-run-id' => '77',
        ]);

        $this->assertSame(1, $exitCode);
    }
}
