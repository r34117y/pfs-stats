<?php

namespace App\Command;

use App\PfsTournamentImport\TournamentImportMetadata;
use App\Service\PfsTournamentCalendarParser;
use App\Service\PfsTournamentImportComparer\PfsTournamentImportComparerInterface;
use App\Service\PfsTournamentImportPlanner;
use App\Service\PfsTournamentImportSqlRenderer;
use App\Service\PfsTournamentResultsParser;
use App\Service\PfsTournamentWebsiteClient;
use DateTimeInterface;
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
        private readonly PfsTournamentImportComparerInterface $comparer,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pfsid', null, InputOption::VALUE_REQUIRED, 'PFS tournament URL id.')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Calendar year for the tournament.', (string) date('Y'))
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
        $pfsId = (int) $input->getOption('pfsid');
        $year = (int) $input->getOption('year');

        if ($pfsId <= 0) {
            $io->error('Option --pfsid must be a positive integer.');

            return Command::INVALID;
        }

        try {
            $calendarTournament = $this->fetchCalendarTournament($year, $pfsId);
            $html = $this->websiteClient->fetchTournamentHtml($pfsId);
            $parsedResults = $this->resultsParser->parse($html);
            $legacyTournamentId = $this->resolveTournamentId($calendarTournament->startDate);
            $shortName = $this->resolveShortName($calendarTournament->location, $calendarTournament->startDate);

            $metadata = new TournamentImportMetadata(
                tournamentId: $legacyTournamentId,
                urlId: $pfsId,
                shortName: $shortName,
                startDate: $calendarTournament->startDate,
                endDate: $calendarTournament->endDate,
                team: $this->stringOrNull($input->getOption('team')) ?? null,
                mcategory: $this->intOrNull($input->getOption('mcategory')) ?? null,
                sertour: $this->intOrNull($input->getOption('sertour')) ?? null,
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

    private function resolveTournamentId(DateTimeInterface $startDate): int
    {
        $id = (int) ($startDate->format('Ymd') . '0');
        $nextDayId = $id + 10;

        $existingId = $this->connection->executeQuery(
            'SELECT max(legacy_id) FROM tournament WHERE legacy_id BETWEEN :from AND :to',
            ['from' => $id, 'to' => $nextDayId]
        )->fetchFirstColumn();

        if ($existingId[0] === null) {
            return $id;
        }

        return $existingId[0] + 1;
    }

    private function resolveShortName(
        string $location,
        \DateTimeImmutable $startDate,
    ): string {
        return sprintf('%s %s', $startDate->format('ymd'), $location);
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
