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
     * Scans multiple domains in parallel using a pool mechanism.
     *
     * As soon as one process finishes, the next domain from the queue is started
     * immediately, so exactly SCAN_CONCURRENCY processes run at all times (until
     * the queue is exhausted). This avoids the straggler-wait problem of the
     * previous batch/chunk approach.
     *
     * @param Domain[] $domains
     * @param callable(string $label): void|null $onDomainScanned Called immediately when each domain's scan process finishes.
     * @return array<int, array{domain: Domain, findings: array<int, array<string, mixed>>, error: string|null}>
     */
    public function scan(array $domains, ?callable $onDomainScanned = null): array
    {
        $concurrency = max(1, $this->config->scanConcurrency);
        /** @var list<Domain> $queue */
        $queue = array_values($domains);
        /** @var array<int, array{process: Process, domain: Domain, done: bool}> $running */
        $running = [];
        $results = [];

        $this->logger->info('ParallelScanner: Starte Pool-Scan', [
            'total_domains' => count($domains),
            'concurrency' => $concurrency,
        ]);

        // Fill the pool initially up to $concurrency slots.
        while (!empty($queue) && count($running) < $concurrency) {
            $domain = array_shift($queue);
            $process = $this->createProcess($domain);
            $process->start();

            $this->logger->debug('ParallelScanner: Prozess gestartet', [
                'domain' => $domain->getFqdn(),
                'port' => $domain->getPort(),
                'pid' => $process->getPid(),
                'queue_remaining' => count($queue),
            ]);

            $running[] = ['process' => $process, 'domain' => $domain, 'done' => false];
        }

        // Poll until all running processes (and any queued ones) are done.
        while (!empty($running)) {
            foreach ($running as &$item) {
                if ($item['done']) {
                    continue;
                }

                if ($item['process']->isTerminated()) {
                    $item['done'] = true;
                    $results[] = $this->collectResult($item['process'], $item['domain']);

                    if ($onDomainScanned !== null) {
                        $onDomainScanned($item['domain']->getFqdn() . ':' . $item['domain']->getPort());
                    }

                    // Immediately fill the freed pool slot with the next queued domain.
                    if (!empty($queue)) {
                        $nextDomain = array_shift($queue);
                        $nextProcess = $this->createProcess($nextDomain);
                        $nextProcess->start();

                        $this->logger->debug('ParallelScanner: Prozess gestartet (nachgerückt)', [
                            'domain' => $nextDomain->getFqdn(),
                            'port' => $nextDomain->getPort(),
                            'pid' => $nextProcess->getPid(),
                            'queue_remaining' => count($queue),
                        ]);

                        $running[] = ['process' => $nextProcess, 'domain' => $nextDomain, 'done' => false];
                    }
                }
            }
            unset($item);

            // Compact finished entries; sleep only if there is still work to do.
            $running = array_values(array_filter($running, static fn (array $i): bool => !$i['done']));

            if (!empty($running)) {
                usleep(100_000); // 100 ms polling interval
            }
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
