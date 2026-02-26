<?php

namespace App\Tests\Integration\Command;

use App\Command\ScanDomainCommand;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for ScanDomainCommand.
 *
 * Uses the real Symfony DI container but tests against known-good
 * and unreachable domains.
 */
#[CoversClass(ScanDomainCommand::class)]
class ScanDomainCommandTest extends IntegrationTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:scan-domain');
        $this->commandTester = new CommandTester($command);
    }

    public function testScanReachableDomainOutputsValidJson(): void
    {
        $exitCode = $this->commandTester->execute([
            'fqdn' => 'google.com',
            'port' => '443',
        ]);

        $this->assertSame(0, $exitCode);

        $output = trim($this->commandTester->getDisplay());
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
        $this->assertArrayHasKey('finding_type', $decoded[0]);
        $this->assertArrayHasKey('severity', $decoded[0]);
        $this->assertArrayHasKey('details', $decoded[0]);
    }

    public function testScanUnreachableDomainOutputsUnreachableFinding(): void
    {
        $exitCode = $this->commandTester->execute([
            'fqdn' => 'nonexistent.invalid.example.com',
            'port' => '12345',
        ]);

        $this->assertSame(0, $exitCode);

        $output = trim($this->commandTester->getDisplay());
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
        $this->assertSame('UNREACHABLE', $decoded[0]['finding_type']);
    }
}
