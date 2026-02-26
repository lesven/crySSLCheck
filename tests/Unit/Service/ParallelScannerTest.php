<?php

namespace App\Tests\Unit\Service;

use App\Entity\Domain;
use App\Service\ParallelScanner;
use App\ValueObject\ScanConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/**
 * Tests for ParallelScanner – chunking, error handling, result parsing.
 */
#[CoversClass(ParallelScanner::class)]
class ParallelScannerTest extends TestCase
{
    // ── Chunking ──────────────────────────────────────────────────────────────

    public function testChunkingWithConcurrencyThreeAndSevenDomains(): void
    {
        $config = new ScanConfiguration(scanConcurrency: 3);
        $scanner = new TestableParallelScanner(new NullLogger(), $config);

        $domains = [];
        for ($i = 0; $i < 7; $i++) {
            $domain = new Domain();
            $domain->setFqdn("example{$i}.com");
            $domain->setPort(443);
            $domains[] = $domain;
        }

        // Configure mock processes to return successful JSON
        $scanner->setMockOutput(json_encode([
            ['finding_type' => 'OK', 'severity' => 'ok', 'details' => []],
        ]));

        $results = $scanner->scan($domains);

        $this->assertCount(7, $results);
        // 3 + 3 + 1 = 3 chunks
        $this->assertSame(3, $scanner->getChunkCount());
    }

    public function testConcurrencyOneRunsSequentially(): void
    {
        $config = new ScanConfiguration(scanConcurrency: 1);
        $scanner = new TestableParallelScanner(new NullLogger(), $config);

        $domains = $this->createDomains(3);

        $scanner->setMockOutput(json_encode([
            ['finding_type' => 'OK', 'severity' => 'ok', 'details' => []],
        ]));

        $results = $scanner->scan($domains);

        $this->assertCount(3, $results);
        // With concurrency 1, we get 3 chunks of 1
        $this->assertSame(3, $scanner->getChunkCount());
    }

    // ── Successful scan ───────────────────────────────────────────────────────

    public function testSuccessfulScanReturnsFindings(): void
    {
        $config = new ScanConfiguration(scanConcurrency: 2);
        $scanner = new TestableParallelScanner(new NullLogger(), $config);

        $domains = $this->createDomains(2);

        $expectedFindings = [
            ['finding_type' => 'OK', 'severity' => 'ok', 'details' => ['protocol' => 'TLSv1.3']],
        ];
        $scanner->setMockOutput(json_encode($expectedFindings));

        $results = $scanner->scan($domains);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertNull($result['error']);
            $this->assertSame($expectedFindings, $result['findings']);
        }
    }

    // ── Error handling ────────────────────────────────────────────────────────

    public function testProcessFailureReturnsError(): void
    {
        $config = new ScanConfiguration(scanConcurrency: 2);
        $scanner = new TestableParallelScanner(new NullLogger(), $config);

        $domains = $this->createDomains(1);

        $scanner->setMockExitCode(1);
        $scanner->setMockErrorOutput('Connection refused');

        $results = $scanner->scan($domains);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]['error']);
        $this->assertStringContainsString('Scan-Prozess fehlgeschlagen', $results[0]['error']);
        $this->assertEmpty($results[0]['findings']);
    }

    public function testInvalidJsonReturnsError(): void
    {
        $config = new ScanConfiguration(scanConcurrency: 2);
        $scanner = new TestableParallelScanner(new NullLogger(), $config);

        $domains = $this->createDomains(1);

        $scanner->setMockOutput('not valid json {{{');

        $results = $scanner->scan($domains);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]['error']);
        $this->assertStringContainsString('Ungültige JSON-Ausgabe', $results[0]['error']);
        $this->assertEmpty($results[0]['findings']);
    }

    public function testProcessTimeoutReturnsError(): void
    {
        $config = new ScanConfiguration(scanConcurrency: 2);
        $scanner = new TestableParallelScanner(new NullLogger(), $config);

        $domains = $this->createDomains(1);

        $scanner->setMockException(new \Symfony\Component\Process\Exception\ProcessTimedOutException(
            new Process(['echo', 'test']),
            \Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL
        ));

        $results = $scanner->scan($domains);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]['error']);
        $this->assertStringContainsString('Prozess-Fehler', $results[0]['error']);
        $this->assertEmpty($results[0]['findings']);
    }

    // ── Domain mapping ────────────────────────────────────────────────────────

    public function testResultsContainCorrectDomainReference(): void
    {
        $config = new ScanConfiguration(scanConcurrency: 5);
        $scanner = new TestableParallelScanner(new NullLogger(), $config);

        $domains = $this->createDomains(3);

        $scanner->setMockOutput(json_encode([
            ['finding_type' => 'OK', 'severity' => 'ok', 'details' => []],
        ]));

        $results = $scanner->scan($domains);

        $this->assertCount(3, $results);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame($domains[$i], $results[$i]['domain']);
        }
    }

    // ── Empty domain list ─────────────────────────────────────────────────────

    public function testEmptyDomainListReturnsEmptyResults(): void
    {
        $config = new ScanConfiguration(scanConcurrency: 5);
        $scanner = new TestableParallelScanner(new NullLogger(), $config);

        $results = $scanner->scan([]);

        $this->assertEmpty($results);
    }

    // ── Mixed results ─────────────────────────────────────────────────────────

    public function testMixedSuccessAndFailureInChunk(): void
    {
        $config = new ScanConfiguration(scanConcurrency: 3);
        $scanner = new TestableParallelScanner(new NullLogger(), $config);

        $domains = $this->createDomains(2);

        // First domain succeeds, second fails
        $scanner->setMockOutputSequence([
            json_encode([['finding_type' => 'OK', 'severity' => 'ok', 'details' => []]]),
            null,  // will use error output
        ]);
        $scanner->setMockExitCodeSequence([0, 1]);
        $scanner->setMockErrorOutputSequence(['', 'Timeout']);

        $results = $scanner->scan($domains);

        $this->assertCount(2, $results);
        $this->assertNull($results[0]['error']);
        $this->assertNotNull($results[1]['error']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return Domain[]
     */
    private function createDomains(int $count): array
    {
        $domains = [];
        for ($i = 0; $i < $count; $i++) {
            $domain = new Domain();
            $domain->setFqdn("example{$i}.com");
            $domain->setPort(443);
            $domains[] = $domain;
        }
        return $domains;
    }
}

/**
 * Testable subclass of ParallelScanner that replaces real subprocess
 * execution with configurable mock behavior.
 */
class TestableParallelScanner extends ParallelScanner
{
    private ?string $mockOutput = null;
    private ?array $mockOutputSequence = null;
    private int $mockExitCode = 0;
    private ?array $mockExitCodeSequence = null;
    private string $mockErrorOutput = '';
    private ?array $mockErrorOutputSequence = null;
    private ?\Throwable $mockException = null;
    private int $chunkCount = 0;
    private int $processIndex = 0;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        ScanConfiguration $config,
    ) {
        parent::__construct($logger, $config, '/dummy');
    }

    public function setMockOutput(string $output): void
    {
        $this->mockOutput = $output;
        $this->mockOutputSequence = null;
    }

    public function setMockOutputSequence(array $sequence): void
    {
        $this->mockOutputSequence = $sequence;
        $this->mockOutput = null;
    }

    public function setMockExitCode(int $exitCode): void
    {
        $this->mockExitCode = $exitCode;
        $this->mockExitCodeSequence = null;
    }

    public function setMockExitCodeSequence(array $sequence): void
    {
        $this->mockExitCodeSequence = $sequence;
    }

    public function setMockErrorOutput(string $errorOutput): void
    {
        $this->mockErrorOutput = $errorOutput;
        $this->mockErrorOutputSequence = null;
    }

    public function setMockErrorOutputSequence(array $sequence): void
    {
        $this->mockErrorOutputSequence = $sequence;
    }

    public function setMockException(\Throwable $exception): void
    {
        $this->mockException = $exception;
    }

    public function getChunkCount(): int
    {
        return $this->chunkCount;
    }

    /**
     * @param Domain[] $domains
     * @return array<int, array{domain: Domain, findings: array, error: string|null}>
     */
    public function scan(array $domains): array
    {
        $this->chunkCount = 0;
        $this->processIndex = 0;
        return parent::scan($domains);
    }

    protected function createProcess(Domain $domain): Process
    {
        $index = $this->processIndex++;

        $output = $this->mockOutputSequence[$index] ?? $this->mockOutput ?? '';
        $exitCode = $this->mockExitCodeSequence[$index] ?? $this->mockExitCode;
        $errorOutput = $this->mockErrorOutputSequence[$index] ?? $this->mockErrorOutput;
        $exception = ($index === 0 || $this->mockOutputSequence === null) ? $this->mockException : null;

        // Track chunk boundaries by counting createProcess calls divided by chunk size
        $concurrency = max(1, (new \ReflectionProperty(parent::class, 'config'))->getValue($this)->scanConcurrency);
        if ($index % $concurrency === 0) {
            $this->chunkCount++;
        }

        return new MockProcess($output, $exitCode, $errorOutput, $exception);
    }
}

/**
 * Mock Process that simulates subprocess behavior without real execution.
 */
class MockProcess extends Process
{
    private bool $started = false;

    public function __construct(
        private readonly string $mockOutput,
        private readonly int $mockExitCode,
        private readonly string $mockErrorOutput,
        private readonly ?\Throwable $mockException = null,
    ) {
        parent::__construct(['echo', 'mock']);
    }

    public function start(?callable $callback = null, array $env = []): void
    {
        $this->started = true;
    }

    public function wait(?callable $callback = null): int
    {
        if ($this->mockException !== null) {
            throw $this->mockException;
        }
        return $this->mockExitCode;
    }

    public function isSuccessful(): bool
    {
        return $this->mockExitCode === 0;
    }

    public function getOutput(): string
    {
        return $this->mockOutput;
    }

    public function getErrorOutput(): string
    {
        return $this->mockErrorOutput;
    }

    public function getExitCode(): ?int
    {
        return $this->mockExitCode;
    }

    public function getPid(): ?int
    {
        return 12345;
    }
}
