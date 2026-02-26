<?php

namespace App\Service;

use App\Entity\Domain;
use App\ValueObject\ScanConfiguration;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Orchestrates parallel domain scanning via Symfony Process subprocesses.
 *
 * Each domain scan is run in its own PHP subprocess (`app:scan-domain`)
 * that outputs JSON on stdout. The main process collects results and
 * returns them for sequential persistence (avoids SQLite write-lock conflicts).
 */
class ParallelScanner
{
    private string $projectDir;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ScanConfiguration $config = new ScanConfiguration(),
        string $projectDir = '',
    ) {
        $this->projectDir = $projectDir;
    }

    /**
     * Scans multiple domains in parallel batches.
     *
     * @param Domain[] $domains
     * @return array<int, array{domain: Domain, findings: array<int, array<string, mixed>>, error: string|null}>
     */
    public function scan(array $domains): array
    {
        $concurrency = max(1, $this->config->scanConcurrency);
        $chunks = array_chunk($domains, $concurrency);
        $results = [];

        $this->logger->info('ParallelScanner: Starte parallelen Scan', [
            'total_domains' => count($domains),
            'concurrency' => $concurrency,
            'chunks' => count($chunks),
        ]);

        foreach ($chunks as $chunkIndex => $chunk) {
            $this->logger->debug('ParallelScanner: Starte Chunk', [
                'chunk' => $chunkIndex + 1,
                'domains' => count($chunk),
            ]);

            $chunkResults = $this->scanChunk($chunk);
            $results = array_merge($results, $chunkResults);
        }

        return $results;
    }

    /**
     * Scans a single chunk of domains in parallel.
     *
     * @param Domain[] $chunk
     * @return array<int, array{domain: Domain, findings: array<int, array<string, mixed>>, error: string|null}>
     */
    private function scanChunk(array $chunk): array
    {
        /** @var array<int, array{process: Process, domain: Domain}> $running */
        $running = [];

        foreach ($chunk as $domain) {
            $process = $this->createProcess($domain);
            $process->start();

            $this->logger->debug('ParallelScanner: Prozess gestartet', [
                'domain' => $domain->getFqdn(),
                'port' => $domain->getPort(),
                'pid' => $process->getPid(),
            ]);

            $running[] = [
                'process' => $process,
                'domain' => $domain,
            ];
        }

        // Wait for all processes in this chunk to finish
        $results = [];
        foreach ($running as $item) {
            $results[] = $this->collectResult($item['process'], $item['domain']);
        }

        return $results;
    }

    /**
     * Creates a Symfony Process for scanning a single domain.
     */
    protected function createProcess(Domain $domain): Process
    {
        $phpBinary = PHP_BINARY;
        $consolePath = $this->projectDir . '/bin/console';

        $process = new Process([
            $phpBinary,
            $consolePath,
            'app:scan-domain',
            $domain->getFqdn(),
            (string) $domain->getPort(),
        ]);

        // Timeout: scan timeout * (retry count + 1) + retry delays + buffer
        $processTimeout = ($this->config->scanTimeout * ($this->config->retryCount + 1))
            + ($this->config->retryDelay * $this->config->retryCount)
            + 10;
        $process->setTimeout($processTimeout);

        return $process;
    }

    /**
     * Waits for a process to finish and parses its JSON output.
     *
     * @return array{domain: Domain, findings: array<int, array<string, mixed>>, error: string|null}
     */
    private function collectResult(Process $process, Domain $domain): array
    {
        try {
            $process->wait();
        } catch (\Throwable $e) {
            $this->logger->error('ParallelScanner: Prozess-Timeout oder Fehler', [
                'domain' => $domain->getFqdn(),
                'port' => $domain->getPort(),
                'error' => $e->getMessage(),
            ]);

            return [
                'domain' => $domain,
                'findings' => [],
                'error' => 'Prozess-Fehler: ' . $e->getMessage(),
            ];
        }

        if (!$process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput() ?: $process->getOutput();
            $this->logger->error('ParallelScanner: Scan-Prozess fehlgeschlagen', [
                'domain' => $domain->getFqdn(),
                'port' => $domain->getPort(),
                'exit_code' => $process->getExitCode(),
                'error' => $errorOutput,
            ]);

            return [
                'domain' => $domain,
                'findings' => [],
                'error' => 'Scan-Prozess fehlgeschlagen (Exit-Code: ' . $process->getExitCode() . '): ' . trim($errorOutput),
            ];
        }

        $output = trim($process->getOutput());
        $decoded = json_decode($output, true);

        if (!is_array($decoded)) {
            $this->logger->error('ParallelScanner: Ungültige JSON-Ausgabe', [
                'domain' => $domain->getFqdn(),
                'port' => $domain->getPort(),
                'output' => substr($output, 0, 500),
            ]);

            return [
                'domain' => $domain,
                'findings' => [],
                'error' => 'Ungültige JSON-Ausgabe vom Scan-Prozess',
            ];
        }

        $this->logger->debug('ParallelScanner: Scan-Ergebnis erhalten', [
            'domain' => $domain->getFqdn(),
            'port' => $domain->getPort(),
            'findings_count' => count($decoded),
        ]);

        return [
            'domain' => $domain,
            'findings' => $decoded,
            'error' => null,
        ];
    }
}
