<?php

namespace App\Tests\Unit\Service;

use App\Entity\Domain;
use App\Entity\ScanRun;
use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Repository\ScanRunRepository;
use App\Service\MailService;
use App\Service\ScanService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

#[CoversClass(ScanService::class)]
class ScanServiceConcurrencyTest extends TestCase
{
    public function testRunFullScanUsesConfiguredConcurrencyAndAggregatesStatus(): void
    {
        $domainRepository = $this->createMock(DomainRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $domains = [
            $this->createDomainWithId(1, 'a.example'),
            $this->createDomainWithId(2, 'b.example'),
            $this->createDomainWithId(3, 'c.example'),
            $this->createDomainWithId(4, 'd.example'),
        ];
        $domainRepository->method('findActive')->willReturn($domains);

        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof ScanRun) {
                $ref = new \ReflectionProperty(ScanRun::class, 'id');
                $ref->setValue($entity, 123);
            }
        });
        $entityManager->method('flush')->willReturn(null);

        $running = 0;
        $maxRunning = 0;

        $service = new class(
            $entityManager,
            $domainRepository,
            $this->createStub(FindingRepository::class),
            $this->createStub(ScanRunRepository::class),
            $this->createStub(MailService::class),
            $running,
            $maxRunning,
        ) extends ScanService {
            /** @var list<Process> */
            public array $processes = [];
            /** @var list<int> */
            public array $startedDomainIds = [];

            public function __construct(
                EntityManagerInterface $entityManager,
                DomainRepository $domainRepository,
                FindingRepository $findingRepository,
                ScanRunRepository $scanRunRepository,
                MailService $mailService,
                private int &$running,
                private int &$maxRunning,
            ) {
                parent::__construct(
                    entityManager: $entityManager,
                    domainRepository: $domainRepository,
                    findingRepository: $findingRepository,
                    scanRunRepository: $scanRunRepository,
                    mailService: $mailService,
                    logger: new NullLogger(),
                    scanTimeout: 1,
                    retryDelay: 0,
                    retryCount: 0,
                    concurrency: 2,
                    notifyOnUnreachable: false,
                    minRsaKeyBits: 2048,
                );
            }

            protected function createScanDomainProcess(int $domainId, int $scanRunId): Process
            {
                $this->startedDomainIds[] = $domainId;
                return array_shift($this->processes);
            }
        };

        $service->processes = [
            $this->buildProcessMock(0, $running, $maxRunning),
            $this->buildProcessMock(1, $running, $maxRunning),
            $this->buildProcessMock(0, $running, $maxRunning),
            $this->buildProcessMock(0, $running, $maxRunning),
        ];

        $scanRun = $service->runFullScan(false, 2);

        $this->assertSame('partial', $scanRun->getStatus());
        $this->assertLessThanOrEqual(2, $maxRunning);
        $this->assertSame([1, 2, 3, 4], $service->startedDomainIds);
    }

    private function createDomainWithId(int $id, string $fqdn): Domain
    {
        $domain = new Domain();
        $domain->setFqdn($fqdn);
        $domain->setPort(443);

        $ref = new \ReflectionProperty(Domain::class, 'id');
        $ref->setValue($domain, $id);

        return $domain;
    }

    private function buildProcessMock(int $exitCode, int &$running, int &$maxRunning): Process
    {
        $process = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['start', 'isTerminated', 'getExitCode'])
            ->getMock();

        $terminated = false;
        $pollCount = 0;

        $process->method('start')->willReturnCallback(function () use (&$running, &$maxRunning): void {
            $running++;
            $maxRunning = max($maxRunning, $running);
        });

        $process->method('isTerminated')->willReturnCallback(function () use (&$terminated, &$pollCount, &$running): bool {
            $pollCount++;
            if (!$terminated && $pollCount >= 2) {
                $terminated = true;
                $running--;
            }

            return $terminated;
        });

        $process->method('getExitCode')->willReturn($exitCode);

        return $process;
    }
}
