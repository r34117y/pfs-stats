<?php

namespace App\Command;

use App\PfsTournamentImport\TournamentImportMetadata;
use App\Service\PfsTournamentCalendarParser;
use App\Service\PfsTournamentImportComparer\PfsTournamentImportComparer;
use App\Service\PfsTournamentImportPlanner;
use App\Service\PfsTournamentImportSqlRenderer;
use App\Service\PfsTournamentResultsParser;
use App\Service\PfsTournamentWebsiteClient;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:pfs:tournaments:plan-import',
    description: 'Builds a draft PFS import plan and SQL preview for one tournament page.',
)]
final class PlanPfsTournamentImportCommand extends Command
{
    public function __construct(
        private readonly PfsTournamentWebsiteClient $websiteClient,
        private readonly PfsTournamentCalendarParser $calendarParser,
        private readonly PfsTournamentResultsParser $resultsParser,
        private readonly PfsTournamentImportPlanner $planner,
        private readonly PfsTournamentImportSqlRenderer $sqlRenderer,
        private readonly PfsTournamentImportComparer $comparer,
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('urlid', null, InputOption::VALUE_REQUIRED, 'PFS tournament URL id.')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Calendar year for the tournament.', (string) date('Y'))
            ->addOption('tournament-id', null, InputOption::VALUE_REQUIRED, 'Target PFSTOURS.id. Defaults to the existing id for this urlid if present.')
            ->addOption('short-name', null, InputOption::VALUE_REQUIRED, 'Target PFSTOURS.name short label.')
            ->addOption('team', null, InputOption::VALUE_REQUIRED, 'Override PFSTOURS.team.')
            ->addOption('mcategory', null, InputOption::VALUE_REQUIRED, 'Override PFSTOURS.mcategory.')
            ->addOption('sertour', null, InputOption::VALUE_REQUIRED, 'Override PFSTOURS.sertour.')
            ->addOption('sql', null, InputOption::VALUE_NONE, 'Render SQL statements after the summary.')
            ->addOption('sql-output', null, InputOption::VALUE_REQUIRED, 'Write rendered SQL to this file path.')
            ->addOption('compare-existing', null, InputOption::VALUE_NONE, 'Compare the generated plan with existing PFSTOURS/PFSTOURWYN/PFSTOURHH rows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $urlId = (int) $input->getOption('urlid');
        $year = (int) $input->getOption('year');

        if ($urlId <= 0) {
            $io->error('Option --urlid must be a positive integer.');

            return Command::INVALID;
        }

        try {
            $calendarTournament = $this->fetchCalendarTournament($year, $urlId);
            $html = $this->websiteClient->fetchTournamentHtml($urlId);
            $parsedResults = $this->resultsParser->parse($html);

            $existingTournament = $this->connection->fetchAssociative(
                'SELECT id, name, team, mcategory, sertour FROM PFSTOURS WHERE urlid = :urlId ORDER BY id DESC LIMIT 1',
                ['urlId' => $urlId],
            );

            $tournamentId = $this->resolveTournamentId($input, $existingTournament);
            $shortName = $this->resolveShortName($input, $existingTournament, $calendarTournament->location, $calendarTournament->endDate);

            $metadata = new TournamentImportMetadata(
                tournamentId: $tournamentId,
                urlId: $urlId,
                shortName: $shortName,
                startDate: $calendarTournament->startDate,
                endDate: $calendarTournament->endDate,
                team: $this->stringOrNull($input->getOption('team')) ?? ($existingTournament['team'] ?? null),
                mcategory: $this->intOrNull($input->getOption('mcategory')) ?? ($existingTournament !== false ? (int) $existingTournament['mcategory'] : null),
                sertour: $this->intOrNull($input->getOption('sertour')) ?? ($existingTournament !== false ? (int) $existingTournament['sertour'] : null),
            );

            $plan = $this->planner->buildPlan($metadata, $parsedResults);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Could not build tournament import plan: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->title(sprintf('PFS Tournament Import Plan (%d)', $plan->tournament->id));
        $io->table(
            ['Field', 'Value'],
            [
                ['Tournament', $plan->tournament->fullname],
                ['Short name', $plan->tournament->name],
                ['URL id', (string) $plan->tournament->urlid],
                ['Players', (string) count($plan->tournamentResults)],
                ['PFSTOURHH rows', (string) count($plan->tournamentGames)],
                ['New players', (string) count($plan->newPlayers)],
            ],
        );

        if ($plan->newPlayers !== []) {
            $io->section('New Players');
            $io->table(
                ['id', 'name_show', 'name_alph'],
                array_map(
                    static fn ($row): array => [(string) $row->id, $row->nameShow, $row->nameAlph],
                    $plan->newPlayers,
                ),
            );
        }

        if ($plan->warnings !== []) {
            $io->section('Warnings');
            foreach ($plan->warnings as $warning) {
                $io->writeln('- ' . $warning);
            }
        }

        $renderSql = (bool) $input->getOption('sql') || $this->stringOrNull($input->getOption('sql-output')) !== null;
        $sql = null;
        if ($renderSql) {
            $sql = $this->sqlRenderer->render($plan);
        }

        $sqlOutputPath = $this->stringOrNull($input->getOption('sql-output'));
        if ($sqlOutputPath !== null) {
            $dir = dirname($sqlOutputPath);
            if (!is_dir($dir)) {
                $io->error(sprintf('SQL output directory does not exist: %s', $dir));

                return Command::FAILURE;
            }

            $bytes = @file_put_contents($sqlOutputPath, $sql ?? '');
            if ($bytes === false) {
                $io->error(sprintf('Could not write SQL output file: %s', $sqlOutputPath));

                return Command::FAILURE;
            }

            $io->writeln(sprintf('SQL written to: %s', $sqlOutputPath));
        }

        if ((bool) $input->getOption('compare-existing')) {
            $comparison = $this->comparer->compare($plan);
            $io->section('Compare Existing');
            if ($comparison->matches) {
                $io->success('Generated plan matches existing PFSTOURS/PFSTOURWYN/PFSTOURHH rows.');
            } else {
                $io->warning(sprintf('Found %d comparison finding(s).', count($comparison->findings)));
                foreach ($comparison->findings as $finding) {
                    $io->writeln('- ' . $finding);
                }
            }
        }

        if ((bool) $input->getOption('sql')) {
            $io->section('SQL Preview');
            $io->writeln($sql ?? '');
        }

        return Command::SUCCESS;
    }

    private function fetchCalendarTournament(int $year, int $urlId): \App\PfsTournamentImport\CalendarTournament
    {
        $calendarHtml = $this->websiteClient->fetchCalendarHtml($year);
        $tournaments = $this->calendarParser->parse($calendarHtml, $year);

        foreach ($tournaments as $tournament) {
            if ($tournament->urlId === $urlId) {
                return $tournament;
            }
        }

        throw new \RuntimeException(sprintf('Tournament urlid %d was not found in calendar year %d.', $urlId, $year));
    }

    /**
     * @param array<string, mixed>|false $existingTournament
     */
    private function resolveTournamentId(InputInterface $input, array|false $existingTournament): int
    {
        $optionValue = $this->intOrNull($input->getOption('tournament-id'));
        if ($optionValue !== null) {
            return $optionValue;
        }

        if ($existingTournament !== false) {
            return (int) $existingTournament['id'];
        }

        throw new \RuntimeException('Option --tournament-id is required when the tournament does not exist in PFSTOURS yet.');
    }

    private function resolveShortName(
        InputInterface $input,
        array|false $existingTournament,
        string $location,
        \DateTimeImmutable $endDate,
    ): string {
        $optionValue = $this->stringOrNull($input->getOption('short-name'));
        if ($optionValue !== null) {
            return $optionValue;
        }

        if ($existingTournament !== false) {
            return (string) $existingTournament['name'];
        }

        return sprintf('%s %s', $endDate->format('ymd'), $location);
    }

    private function intOrNull(mixed $value): ?int
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
