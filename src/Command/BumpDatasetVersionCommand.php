<?php

namespace App\Command;

use App\Service\DatasetVersionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:dataset-version:bump',
    description: 'Bump dataset cache version to invalidate read caches after MySQL dump import.',
)]
final class BumpDatasetVersionCommand extends Command
{
    public function __construct(
        private readonly DatasetVersionService $datasetVersionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'value',
            null,
            InputOption::VALUE_REQUIRED,
            'Explicit dataset version value. If omitted, an UTC timestamp-based value is generated.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $currentVersion = $this->datasetVersionService->getVersion();
        $newVersion = $this->datasetVersionService->bumpVersion($input->getOption('value'));

        $io->success(sprintf('Dataset cache version changed: %s -> %s', $currentVersion, $newVersion));
        $io->writeln('Use this command right after importing a new MySQL dump.');

        return Command::SUCCESS;
    }
}
