<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ClubTournamentResultsImportService;
use App\Service\ClubTournamentResultsParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:clubs:tournaments:import',
    description: 'Imports one club HH tournament results file into the database.',
)]
final class ImportClubTournamentResultsCommand extends Command
{
    public function __construct(
        private readonly ClubTournamentResultsParser        $parser,
        private readonly ClubTournamentResultsImportService $importService,
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
            ->addArgument('organization-id', InputArgument::REQUIRED, 'Target organization database id.');
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
            $parsed = $this->parser->parse($this->decodeText($raw));
            $result = $this->importService->import($parsed, $organizationId);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Could not import tournament: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Imported %s as tournament legacy id %d (database id %d).',
            $parsed->name,
            $result->legacyTournamentId,
            $result->tournamentId,
        ));
        $io->table(
            ['Metric', 'Value'],
            [
                ['Players', (string) $result->playersCount],
                ['Games', (string) $result->gamesCount],
                ['Created players', (string) count($result->createdPlayerIds)],
                ['Linked existing players to organization', (string) count($result->linkedPlayerIds)],
            ],
        );

        return Command::SUCCESS;
    }

    private function decodeText(string $raw): string
    {
        if (@preg_match('//u', $raw) === 1) {
            return $raw;
        }

        foreach (['Windows-1250', 'ISO-8859-2'] as $encoding) {
            $decoded = @iconv($encoding, 'UTF-8//IGNORE', $raw);
            if ($decoded !== false && @preg_match('//u', $decoded) === 1) {
                return $decoded;
            }
        }

        throw new \RuntimeException('Could not decode file as UTF-8, Windows-1250, or ISO-8859-2.');
    }
}
