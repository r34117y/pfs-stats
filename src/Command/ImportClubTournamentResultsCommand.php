<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ClubTournamentResultsImportService;
use App\Service\ClubTournamentResultsFileDecoder;
use App\Service\ClubTournamentResultsParser;
use App\Service\DatasetVersionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:clubs:tournaments:import',
    description: 'Imports one club HH tournament results file into the database.',
)]
final class ImportClubTournamentResultsCommand extends Command
{
    public function __construct(
        private readonly ClubTournamentResultsFileDecoder   $fileDecoder,
        private readonly ClubTournamentResultsParser        $parser,
        private readonly ClubTournamentResultsImportService $importService,
        private readonly ?DatasetVersionService $datasetVersion = null,
        private readonly ?\App\Service\ClubTournamentMetadataResolver $metadataResolver = null,
    ) {
        parent::__construct();
    }

    /**
     * Example usage:
     * php bin/console app:clubs:tournaments:import blubry_wyniki/24022026_Blubry528_SyntinaHH.txt 4
     */
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the HH file.')
            ->addArgument('organization-id', InputArgument::REQUIRED, 'Target organization database id.')
            ->addOption('standings', null, InputOption::VALUE_REQUIRED, 'Final standings file; defaults to the adjacent file without HH in its name.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = trim((string) $input->getArgument('path'));
        $organizationId = (int) $input->getArgument('organization-id');

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            $io->error(sprintf('File not found or not readable: %s', $path));

            return Command::INVALID;
        }

        if ($organizationId <= 0) {
            $io->error('Organization id must be a positive integer.');

            return Command::INVALID;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $io->error(sprintf('Could not read file: %s', $path));

            return Command::FAILURE;
        }

        try {
            $standingsPath = $input->getOption('standings');
            if ($standingsPath === null && preg_match('/HH\.txt$/i', $path)) {
                $candidate = preg_replace('/HH\.txt$/i', '.txt', $path);
                $standingsPath = is_file($candidate) ? $candidate : null;
            }
            $standings = null;
            if ($standingsPath !== null) {
                if (!is_file($standingsPath) || !is_readable($standingsPath) || ($standings = file_get_contents($standingsPath)) === false) {
                    throw new \RuntimeException('Could not read final standings file: '.$standingsPath);
                }
                $standings = $this->fileDecoder->decode($standings);
            }
            $parsed = $this->parser->parse($this->fileDecoder->decode($raw), $standings);
            $metadata = $this->metadataResolver ?? new \App\Service\ClubTournamentMetadataResolver();
            $parsed = $metadata->apply($parsed, $metadata->directoryFromPath($path));
            $result = $this->importService->import($parsed, $organizationId);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Could not import tournament: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        try {
            $this->datasetVersion?->bumpVersion();
        } catch (\Throwable $exception) {
            $io->warning(sprintf('Tournament %d is saved, but cache refresh failed: %s. Run app:cache:refresh-after-import.', $result->tournamentId, $exception->getMessage()));
        }

        $io->success(sprintf(
            'Imported %s as tournament database id %d.',
            $parsed->name,
            $result->tournamentId,
        ));
        $io->table(
            ['Metric', 'Value'],
            [
                ['Players', (string) $result->playersCount],
                ['Games', (string) $result->gamesCount],
                ['Published ranking rows', (string) $result->rankingRowsCount],
                ['Created players', (string) count($result->createdPlayerIds)],
                ['Linked existing players to organization', (string) count($result->linkedPlayerIds)],
            ],
        );

        if ($result->rankingWarning !== null) {
            $io->warning($result->rankingWarning);
        }

        return Command::SUCCESS;
    }
}
