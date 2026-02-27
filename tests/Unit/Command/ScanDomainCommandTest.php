<?php

namespace App\Tests\Unit\Command;

use App\Command\ScanDomainCommand;
use App\Service\ScanService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for ScanDomainCommand – JSON output, error handling.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ScanDomainCommand::class)]
class ScanDomainCommandTest extends TestCase
{
    private MockObject&ScanService $scanService;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->scanService = $this->createMock(ScanService::class);

        $command = new ScanDomainCommand(
            $this->scanService,
            new NullLogger(),
        );

        $app = new Application();
        $app->addCommand($command);

        $this->commandTester = new CommandTester($app->find('app:scan-domain'));
    }

    public function testOutputsValidJsonOnSuccess(): void
    {
        $findings = [
            ['finding_type' => 'OK', 'severity' => 'ok', 'details' => ['protocol' => 'TLSv1.3']],
        ];

        $this->scanService
            ->expects($this->once())
            ->method('scanDomainByFqdn')
            ->with('example.com', 443)
            ->willReturn($findings);

        $exitCode = $this->commandTester->execute([
            'fqdn' => 'example.com',
            'port' => '443',
        ]);

        $this->assertSame(0, $exitCode);

        $output = $this->commandTester->getDisplay();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertSame('OK', $decoded[0]['finding_type']);
    }

    public function testOutputsMultipleFindings(): void
    {
        $findings = [
            ['finding_type' => 'CERT_EXPIRY', 'severity' => 'high', 'details' => ['days_remaining' => 5]],
            ['finding_type' => 'TLS_VERSION', 'severity' => 'high', 'details' => ['protocol' => 'TLSv1.0']],
        ];

        $this->scanService
            ->method('scanDomainByFqdn')
            ->willReturn($findings);

        $exitCode = $this->commandTester->execute([
            'fqdn' => 'insecure.example.com',
            'port' => '443',
        ]);

        $this->assertSame(0, $exitCode);

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertCount(2, $decoded);
    }

    public function testReturnsFailureOnException(): void
    {
        $this->scanService
            ->method('scanDomainByFqdn')
            ->willThrowException(new \RuntimeException('TLS handshake failed'));

        $exitCode = $this->commandTester->execute([
            'fqdn' => 'broken.example.com',
            'port' => '443',
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function testPassesCorrectPortAsInteger(): void
    {
        $this->scanService
            ->expects($this->once())
            ->method('scanDomainByFqdn')
            ->with('example.com', 8443)
            ->willReturn([]);

        $this->commandTester->execute([
            'fqdn' => 'example.com',
            'port' => '8443',
        ]);
    }
}
