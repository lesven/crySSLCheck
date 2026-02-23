<?php

namespace App\Command;

use App\Repository\DomainRepository;
use App\Repository\ScanRunRepository;
use App\Service\ScanService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:scan-domain',
    description: 'Scannt eine einzelne Domain innerhalb eines Scan-Runs',
    hidden: true,
)]
class ScanDomainCommand extends Command
{
    public function __construct(
        private readonly DomainRepository $domainRepository,
        private readonly ScanRunRepository $scanRunRepository,
        private readonly ScanService $scanService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('domain-id', InputArgument::REQUIRED)
            ->addArgument('scan-run-id', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $domainId = (int) $input->getArgument('domain-id');
            $scanRunId = (int) $input->getArgument('scan-run-id');

            $domain = $this->domainRepository->find($domainId);
            $scanRun = $this->scanRunRepository->find($scanRunId);

            if ($domain === null || $scanRun === null) {
                return 2;
            }

            return $this->scanService->scanAndPersistDomain($domain, $scanRun);
        } catch (\Throwable $exception) {
            $output->writeln(sprintf(
                '<error>Unexpected error while executing scan for domain-id "%s" and scan-run-id "%s": [%s] %s</error>',
                (string) $input->getArgument('domain-id'),
                (string) $input->getArgument('scan-run-id'),
                get_class($exception),
                $exception->getMessage()
            ));
            return 2;
        }
    }
}
