<?php

namespace App\Command;

use App\Enum\ScanRunStatus;
use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Repository\ScanRunRepository;
use App\Service\ScanService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:scan',
    description: 'Scannt alle aktiven Domains auf TLS-Probleme',
)]
class ScanCommand extends Command
{
    public function __construct(
        private readonly ScanService $scanService,
        private readonly ScanRunRepository $scanRunRepository,
        private readonly FindingRepository $findingRepository,
        private readonly DomainRepository $domainRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Erzwingt Scan auch wenn bereits ein erfolgreicher Scan heute existiert'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        if (!$force) {
            $todayRun = $this->scanRunRepository->findLatestSuccessfulToday();
            if ($todayRun && $todayRun->getStatus() === ScanRunStatus::Success->value) {
                $io->note("Scan für heute bereits erfolgreich durchgeführt (Run #{$todayRun->getId()}). Überspringe.");
                $io->note('Verwende --force / -f um trotzdem zu scannen.');
                return Command::SUCCESS;
            }
        } else {
            $io->note('Force-Option erkannt – erzwinge Scan trotz vorherigem erfolgreichen Lauf.');
        }

        $io->title('TLS Monitor – Starte vollständigen Scan');
        $io->text('Zeitpunkt: ' . (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

        try {
            $domains = $this->domainRepository->findActive();
            $totalDomains = count($domains);

            if ($totalDomains === 0) {
                $scanRun = $this->scanService->runFullScan();
            } else {
                $progressBar = new ProgressBar($output, $totalDomains);
                $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
                $progressBar->setMessage('Starte...');
                $progressBar->start();

                $scanRun = $this->scanService->runFullScan(function (string $fqdn) use ($progressBar): void {
                    $progressBar->setMessage($fqdn);
                    $progressBar->advance();
                });

                $progressBar->setMessage('Fertig.');
                $progressBar->finish();
                $io->newLine(2);
            }

            if ($scanRun->getFinishedAt() === null) {
                $io->warning('Keine aktiven Domains vorhanden.');
                return Command::SUCCESS;
            }

            $findings = $this->findingRepository->findByRunId($scanRun->getId());

            $io->success("Scan abgeschlossen!");
            $io->table(
                ['Eigenschaft', 'Wert'],
                [
                    ['Run-ID', '#' . $scanRun->getId()],
                    ['Status', $scanRun->getStatus()],
                    ['Gestartet', $scanRun->getStartedAt()?->format('Y-m-d H:i:s')],
                    ['Beendet', $scanRun->getFinishedAt()?->format('Y-m-d H:i:s')],
                    ['Findings', count($findings)],
                ]
            );

            // Summary by finding type
            $summary = [];
            foreach ($findings as $finding) {
                $type = $finding->getFindingType();
                $summary[$type] = ($summary[$type] ?? 0) + 1;
            }

            if (!empty($summary)) {
                $io->section('Zusammenfassung');
                $rows = [];
                foreach ($summary as $type => $count) {
                    $rows[] = [$type, $count];
                }
                $io->table(['Finding-Typ', 'Anzahl'], $rows);
            }

            return match ($scanRun->getStatus()) {
                ScanRunStatus::Success->value => Command::SUCCESS,
                ScanRunStatus::Partial->value  => Command::SUCCESS,
                default                        => Command::FAILURE,
            };

        } catch (\Throwable $e) {
            $io->error('FEHLER: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
