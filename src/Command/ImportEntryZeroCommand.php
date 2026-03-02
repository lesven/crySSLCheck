<?php

namespace App\Command;

use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Service\TlsConnectorInterface;
use App\Service\ValidationService;
use App\ValueObject\ScanConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-entry-zero',
    description: 'Importiert Subdomains aus einer Entry-Zero-CSV-Datei (Port 443, nur erreichbare Domains)',
)]
class ImportEntryZeroCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DomainRepository $domainRepository,
        private readonly ValidationService $validationService,
        private readonly TlsConnectorInterface $tlsConnector,
        private readonly ScanConfiguration $scanConfiguration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Pfad zur Entry-Zero-CSV-Datei'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Nur validieren, nichts persistieren, kein Erreichbarkeits-Check'
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Anzahl der Domains vor einem Flush (Standard: 50)',
                '50'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('file');
        $dryRun   = (bool) $input->getOption('dry-run');
        $batchSize = max(1, (int) $input->getOption('batch-size'));

        if (!is_string($filePath) || !file_exists($filePath) || !is_readable($filePath)) {
            $io->error("Datei nicht gefunden oder nicht lesbar: {$filePath}");
            return Command::FAILURE;
        }

        $io->title('Entry Zero Import' . ($dryRun ? ' [DRY RUN]' : ''));
        $io->text('Datei: ' . $filePath);

        // ── Phase 1: Header-Validierung + Zeilen zählen ───────────────────────
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $io->error('Datei konnte nicht geöffnet werden.');
            return Command::FAILURE;
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header === false) {
            fclose($handle);
            $io->error('CSV-Datei ist leer oder ungültig.');
            return Command::FAILURE;
        }

        $header = array_map(fn (string $h) => strtolower(trim($h)), $header);

        $subdomainsCol = array_search('subdomains', $header, true);
        $companyCol    = array_search('company', $header, true);

        if ($subdomainsCol === false) {
            fclose($handle);
            $io->error('CSV muss mindestens die Spalte "Subdomains" enthalten.');
            return Command::FAILURE;
        }

        $totalRows = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (!empty(array_filter($row, fn ($v) => $v !== null && $v !== ''))) {
                ++$totalRows;
            }
        }
        rewind($handle);
        fgetcsv($handle, 0, ',', '"', ''); // Header überspringen

        $io->text(sprintf('Gefundene Datenzeilen: %d', $totalRows));
        $io->newLine();

        // ── Phase 2: Verarbeitung mit Fortschrittsanzeige ─────────────────────
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        /** @var list<string> $errorMessages */
        $errorMessages = [];
        $batchCount    = 0;
        $lineNumber    = 1;

        $progressBar = new ProgressBar($output, $totalRows);
        $progressBar->setFormat(
            ' %current%/%max% [%bar%] %percent:3s%%  %elapsed:6s% / ~%estimated:-6s%  %message%'
        );
        $progressBar->setMessage('Starte...');
        $progressBar->start();

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            ++$lineNumber;

            if (empty(array_filter($row, fn ($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $rawSubdomains = trim((string) ($row[$subdomainsCol] ?? ''));
            $company       = $companyCol !== false ? (trim((string) ($row[$companyCol] ?? '')) ?: null) : null;

            $progressBar->setMessage($rawSubdomains !== '' ? mb_substr($rawSubdomains, 0, 50) : 'Zeile ' . $lineNumber);
            $progressBar->advance();

            if ($rawSubdomains === '') {
                ++$stats['errors'];
                $errorMessages[] = sprintf('Zeile %d: Spalte "Subdomains" ist leer.', $lineNumber);
                continue;
            }

            $subdomains = self::parseSubdomains($rawSubdomains);

            foreach ($subdomains as $fqdn) {
                $fqdn = trim($fqdn);

                $validationErrors = $this->validationService->validateDomainForImport($fqdn, 443);
                if (!empty($validationErrors)) {
                    ++$stats['errors'];
                    $errorMessages[] = sprintf('Zeile %d (%s): %s', $lineNumber, $fqdn, implode(', ', $validationErrors));
                    continue;
                }

                if (!$dryRun) {
                    $tlsResult = $this->tlsConnector->connect($fqdn, 443, $this->scanConfiguration->scanTimeout);
                    if ($tlsResult === null) {
                        ++$stats['skipped'];
                        continue;
                    }

                    $existing = $this->domainRepository->findOneBy(['fqdn' => $fqdn, 'port' => 443]);
                    if ($existing !== null) {
                        $existing->setDescription($company);
                        ++$stats['updated'];
                    } else {
                        $domain = new Domain();
                        $domain->setFqdn($fqdn);
                        $domain->setPort(443);
                        $domain->setDescription($company);
                        $this->entityManager->persist($domain);
                        ++$stats['created'];
                    }

                    ++$batchCount;
                    if ($batchCount % $batchSize === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                    }
                } else {
                    ++$stats['created']; // Dry-run: als "würde erstellt" zählen
                }
            }
        }

        fclose($handle);

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $progressBar->setMessage('Fertig.');
        $progressBar->finish();
        $io->newLine(2);

        // ── Zusammenfassung ───────────────────────────────────────────────────
        if ($dryRun) {
            $io->note('DRY RUN – keine Änderungen in der Datenbank vorgenommen.');
        }

        $io->success('Import abgeschlossen!');
        $io->table(
            ['Ergebnis', 'Anzahl'],
            [
                ['✓ Neu angelegt' . ($dryRun ? ' (simuliert)' : ''), $stats['created']],
                ['↺ Aktualisiert',                                    $stats['updated']],
                ['✗ Nicht erreichbar (übersprungen)',                 $stats['skipped']],
                ['! Validierungsfehler',                              $stats['errors']],
            ]
        );

        if (!empty($errorMessages)) {
            $io->section('Fehlerdetails');
            foreach ($errorMessages as $msg) {
                $io->text('  ' . $msg);
            }
        }

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Parses the Subdomains column value from an Entry Zero CSV row.
     * Handles both JSON arrays (["a.example.de","b.example.de"]) and
     * plain single-value strings ("www.example.de").
     *
     * @return list<string>
     */
    public static function parseSubdomains(string $value): array
    {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return [$value];
    }
}
