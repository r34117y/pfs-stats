<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ClubTournamentRankingImportService;
use App\Service\DatasetVersionService;
use App\ClubTournamentImport\ClubTournamentRankingUnavailable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:clubs:rankings:import', description: 'Import published club ranking snapshots for existing tournaments in an explicit date range.')]
final class ImportClubRankingsCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly ClubTournamentRankingImportService $importer,
        private readonly DatasetVersionService $datasetVersion,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('organization-id', InputArgument::REQUIRED)
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First tournament date, YYYY-MM-DD (required).')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last tournament date, YYYY-MM-DD (required).')
            ->addOption('skip-unpublished', null, InputOption::VALUE_NONE, 'Explicitly skip unavailable ranking sources.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Fetch and validate all rankings without database writes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $org = filter_var($input->getArgument('organization-id'), FILTER_VALIDATE_INT);
        $from = $this->dateCode($input->getOption('from'));
        $to = $this->dateCode($input->getOption('to'));
        if (!$org || $org < 1 || $from === null || $to === null || $from > $to) {
            $io->error('Provide a positive organization id and a valid --from / --to date range.');
            return Command::INVALID;
        }
        try {
            $tournaments = $this->connection->fetchAllAssociative('SELECT id,dt,COALESCE(fullname,name) AS name FROM tournament WHERE organization_id=? AND dt BETWEEN ? AND ? ORDER BY dt,id', [$org, $from, $to]);
            if ($tournaments === []) {
                throw new \RuntimeException('No tournaments in the requested organization/date range.');
            }
            $snapshots = [];
            foreach ($tournaments as $tournament) {
                try {
                    $snapshot = $this->importer->fetchSnapshot($org, (int) $tournament['dt']);
                } catch (ClubTournamentRankingUnavailable $exception) {
                    if (!$input->getOption('skip-unpublished')) {
                        throw $exception;
                    }
                    $io->warning(sprintf('Skipped %s: %s', $tournament['name'], $exception->getMessage()));
                    continue;
                }
                if ($snapshot === null) {
                    throw new \RuntimeException('No published ranking source configured for this organization.');
                }
                $this->importer->validateSnapshot($snapshot, $org, (int) $tournament['id']);
                $snapshots[] = [$tournament, $snapshot];
                $io->writeln(sprintf('Validated %s: %d ranking rows (%s).', $tournament['name'], count($snapshot->rows), $snapshot->hasGameCounts() ? 'complete' : 'game counts unavailable'));
            }
            if ($snapshots === []) {
                throw new \RuntimeException('No complete published ranking snapshots were found.');
            }
            if ($input->getOption('dry-run')) {
                $io->success(sprintf('Validated %d snapshots; no database writes.', count($snapshots)));
                return Command::SUCCESS;
            }
            $inserted = $this->connection->transactional(function () use ($org, $snapshots): int {
                $inserted = 0;
                foreach ($snapshots as [$tournament, $snapshot]) {
                    $inserted += $this->importer->store($snapshot, $org, (int) $tournament['id']);
                }
                return $inserted;
            });
        } catch (\Throwable $exception) {
            $io->error('Could not import club rankings: '.$exception->getMessage());
            return Command::FAILURE;
        }
        if ($inserted > 0) {
            try {
                $this->datasetVersion->bumpVersion();
            } catch (\Throwable $exception) {
                $io->warning('Rankings are saved; cache publication failed. Run app:cache:refresh-after-import. '.$exception->getMessage());
            }
        }
        $io->success(sprintf('Processed %d snapshots, inserted %d ranking rows.', count($snapshots), $inserted));
        return Command::SUCCESS;
    }

    private function dateCode(mixed $value): ?int
    {
        if (!is_string($value)) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? (int) $date->format('Ymd') : null;
    }
}
