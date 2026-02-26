<?php

namespace App\Command;

use App\Service\ScanService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scans a single domain and outputs findings as JSON on stdout.
 *
 * This command is designed to be called by ParallelScanner as a subprocess.
 * It performs only the network I/O (TLS check) and certificate analysis,
 * returning raw finding arrays as JSON. Persistence is handled by the
 * calling process.
 *
 * Exit codes:
 *   0 = success (JSON findings on stdout)
 *   1 = error (error message on stderr)
 */
#[AsCommand(
    name: 'app:scan-domain',
    description: 'Scannt eine einzelne Domain und gibt Findings als JSON aus (für parallele Ausführung)',
)]
class ScanDomainCommand extends Command
{
    public function __construct(
        private readonly ScanService $scanService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fqdn', InputArgument::REQUIRED, 'FQDN der zu scannenden Domain')
            ->addArgument('port', InputArgument::REQUIRED, 'Port der zu scannenden Domain');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fqdn = $input->getArgument('fqdn');
        $port = (int) $input->getArgument('port');

        try {
            $this->logger->debug('ScanDomainCommand: Starte Scan', [
                'fqdn' => $fqdn,
                'port' => $port,
            ]);

            $findings = $this->scanService->scanDomainByFqdn($fqdn, $port);

            // Output raw JSON to stdout – no Symfony output formatting
            $output->write(json_encode($findings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('ScanDomainCommand: Fehler beim Scannen', [
                'fqdn' => $fqdn,
                'port' => $port,
                'error' => $e->getMessage(),
            ]);

            // Write error to stderr so ParallelScanner can capture it
            if ($output instanceof ConsoleOutputInterface) {
                $output->getErrorOutput()->write($e->getMessage());
            }

            return Command::FAILURE;
        }
    }
}
