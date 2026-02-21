<?php

namespace App\Command;

use App\Repository\FindingRepository;
use App\Repository\ScanRunRepository;
use App\Service\ScanService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
            if ($todayRun && $todayRun->getStatus() === 'success') {
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
            $scanRun = $this->scanService->runFullScan();

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
                'success' => Command::SUCCESS,
                'partial'  => Command::SUCCESS,
                default    => Command::FAILURE,
            };

        } catch (\Throwable $e) {
            $io->error('FEHLER: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
