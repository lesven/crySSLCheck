<?php

namespace App\Tests\Unit\Command;

use App\Command\ImportEntryZeroCommand;
use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Service\TlsConnectorInterface;
use App\Service\ValidationService;
use App\ValueObject\ScanConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ImportEntryZeroCommand::class)]
class ImportEntryZeroCommandTest extends TestCase
{
    private MockObject&EntityManagerInterface $em;
    private MockObject&DomainRepository $domainRepository;
    private MockObject&ValidationService $validationService;
    private MockObject&TlsConnectorInterface $tlsConnector;
    private ScanConfiguration $config;

    protected function setUp(): void
    {
        $this->em               = $this->createMock(EntityManagerInterface::class);
        $this->domainRepository = $this->createMock(DomainRepository::class);
        $this->validationService = $this->createMock(ValidationService::class);
        $this->tlsConnector     = $this->createMock(TlsConnectorInterface::class);
        $this->config           = new ScanConfiguration(scanTimeout: 5);
    }

    private function buildTester(): CommandTester
    {
        $command = new ImportEntryZeroCommand(
            $this->em,
            $this->domainRepository,
            $this->validationService,
            $this->tlsConnector,
            $this->config,
        );

        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('app:import-entry-zero'));
    }

    private function writeTmpCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ez_test_') . '.csv';
        file_put_contents($path, $content);
        return $path;
    }

    // ── parseSubdomains ──────────────────────────────────────────────────────

    public function testParseSubdomainsJsonArray(): void
    {
        $result = ImportEntryZeroCommand::parseSubdomains('["a.example.de","b.example.de"]');
        $this->assertSame(['a.example.de', 'b.example.de'], $result);
    }

    public function testParseSubdomainsSingleString(): void
    {
        $result = ImportEntryZeroCommand::parseSubdomains('www.example.de');
        $this->assertSame(['www.example.de'], $result);
    }

    public function testParseSubdomainsFiltersEmptyJsonEntries(): void
    {
        $result = ImportEntryZeroCommand::parseSubdomains('["a.example.de","","b.example.de"]');
        $this->assertSame(['a.example.de', 'b.example.de'], $result);
    }

    // ── File-Validierung ─────────────────────────────────────────────────────

    public function testFailsOnMissingFile(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['file' => '/does/not/exist.csv']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('nicht gefunden', $tester->getDisplay());
    }

    public function testFailsOnMissingSubdomainsHeader(): void
    {
        $path = $this->writeTmpCsv("Main Domain,Country\ndomain1.de,DE\n");
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['file' => $path]);
        unlink($path);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Subdomains', $tester->getDisplay());
    }

    public function testFailsOnEmptyFile(): void
    {
        $path = $this->writeTmpCsv('');
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['file' => $path]);
        unlink($path);

        $this->assertSame(1, $exitCode);
    }

    // ── Erfolgreicher Import ─────────────────────────────────────────────────

    public function testImportsJsonArraySubdomains(): void
    {
        $this->validationService->method('validateDomainForImport')->willReturn([]);
        $this->tlsConnector->method('connect')->willReturn(['subject' => 'CN=test']);
        $this->domainRepository->method('findOneBy')->willReturn(null);

        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $content  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $content .= "\"domain1.de\",2,\"[\"\"shop.domain1.de\"\",\"\"www.domain1.de\"\"]\",DE,\"Firma Eins\"\n";
        $path     = $this->writeTmpCsv($content);

        $tester   = $this->buildTester();
        $exitCode = $tester->execute(['file' => $path]);
        unlink($path);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Neu angelegt', $display);
        $this->assertStringContainsString('2', $display);
    }

    public function testImportsSingleSubdomain(): void
    {
        $this->validationService->method('validateDomainForImport')->willReturn([]);
        $this->tlsConnector->method('connect')->willReturn(['subject' => 'CN=test']);
        $this->domainRepository->method('findOneBy')->willReturn(null);

        $content  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $content .= "\"domain4.de\",1,\"www.domain4.de\",BE,\"Firma Vier\"\n";
        $path     = $this->writeTmpCsv($content);

        $tester   = $this->buildTester();
        $exitCode = $tester->execute(['file' => $path]);
        unlink($path);

        $this->assertSame(0, $exitCode);
    }

    public function testUpdatesDuplicateDomain(): void
    {
        $this->validationService->method('validateDomainForImport')->willReturn([]);
        $this->tlsConnector->method('connect')->willReturn(['subject' => 'CN=test']);

        $existing = new Domain();
        $existing->setFqdn('www.existing.de');
        $existing->setPort(443);
        $this->domainRepository->method('findOneBy')->willReturn($existing);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $content  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $content .= "\"existing.de\",1,\"www.existing.de\",DE,\"Neue Firma\"\n";
        $path     = $this->writeTmpCsv($content);

        $tester   = $this->buildTester();
        $exitCode = $tester->execute(['file' => $path]);
        unlink($path);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Aktualisiert', $display);
        $this->assertSame('Neue Firma', $existing->getDescription());
    }

    // ── Erreichbarkeit ───────────────────────────────────────────────────────

    public function testSkipsUnreachableDomains(): void
    {
        $this->validationService->method('validateDomainForImport')->willReturn([]);
        $this->tlsConnector->method('connect')->willReturn(null);

        $this->em->expects($this->never())->method('persist');

        $content  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $content .= "\"domain1.de\",1,\"www.domain1.de\",DE,\"Firma\"\n";
        $path     = $this->writeTmpCsv($content);

        $tester   = $this->buildTester();
        $exitCode = $tester->execute(['file' => $path]);
        unlink($path);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Nicht erreichbar', $display);
        $this->assertStringContainsString('1', $display);
    }

    // ── Validierungsfehler ───────────────────────────────────────────────────

    public function testCountsValidationErrors(): void
    {
        $this->validationService->method('validateDomainForImport')->willReturn(['Ungültiger FQDN']);

        $this->em->expects($this->never())->method('persist');

        $content  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $content .= "\"bad.de\",1,\"not_a_valid_domain\",DE,\"Firm\"\n";
        $path     = $this->writeTmpCsv($content);

        $tester   = $this->buildTester();
        $exitCode = $tester->execute(['file' => $path]);
        unlink($path);

        // Returns FAILURE (exit 1) when errors exist
        $this->assertSame(1, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Validierungsfehler', $display);
        $this->assertStringContainsString('not_a_valid_domain', $display);
    }

    // ── Dry Run ──────────────────────────────────────────────────────────────

    public function testDryRunDoesNotPersist(): void
    {
        $this->validationService->method('validateDomainForImport')->willReturn([]);
        $this->tlsConnector->expects($this->never())->method('connect');
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $content  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $content .= "\"domain1.de\",1,\"www.domain1.de\",DE,\"Firma\"\n";
        $path     = $this->writeTmpCsv($content);

        $tester   = $this->buildTester();
        $exitCode = $tester->execute(['file' => $path, '--dry-run' => true]);
        unlink($path);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('DRY RUN', $display);
    }

    // ── Batch-Flush ──────────────────────────────────────────────────────────

    public function testBatchFlushInterval(): void
    {
        $this->validationService->method('validateDomainForImport')->willReturn([]);
        $this->tlsConnector->method('connect')->willReturn(['subject' => 'CN=test']);
        $this->domainRepository->method('findOneBy')->willReturn(null);

        // 1 flush per batch of 2, plus final flush → 3 domains → 2 flushes (at 2 and final)
        $this->em->expects($this->exactly(2))->method('flush');

        $content  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $content .= "\"d.de\",3,\"[\"\"a.d.de\"\",\"\"b.d.de\"\",\"\"c.d.de\"\"]\",DE,\"X\"\n";
        $path     = $this->writeTmpCsv($content);

        $tester   = $this->buildTester();
        $tester->execute(['file' => $path, '--batch-size' => '2']);
        unlink($path);
    }
}
