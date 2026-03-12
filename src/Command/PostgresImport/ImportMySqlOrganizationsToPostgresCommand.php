<?php

declare(strict_types=1);

namespace App\Command\PostgresImport;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:db:import-mysql-organizations',
    description: 'Imports organization-prefixed MySQL tables into the unified PostgreSQL schema.',
)]
final class ImportMySqlOrganizationsToPostgresCommand extends Command {
    /** @var list<string> */
    private const array BASE_SUFFIXES = [
        'PLAYER',
        'PLAYSUMM',
        'RANKING',
        'SERTOUR',
        'TOURHH',
        'TOURS',
        'TOURWYN',
        'TRESOURCE',
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $postgresConnection,
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private readonly Connection $mysqlConnection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'organizations',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated organization codes (table prefixes). Defaults to all discovered organizations.',
            )
            ->addOption(
                'no-truncate',
                null,
                InputOption::VALUE_NONE,
                'Do not truncate PostgreSQL target tables before import.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Execute the import in a transaction and roll it back at the end.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $requestedOrganizations = $this->parseOrganizationsOption($input->getOption('organizations'));
        if ($requestedOrganizations === null) {
            $io->error('Option --organizations must contain only uppercase codes separated by commas, e.g. PFS,ANAGR.');

            return Command::INVALID;
        }

        $organizationTables = $this->discoverOrganizationTables();
        $organizations = $this->resolveOrganizationsToImport($organizationTables, $requestedOrganizations, $io);
        if ($organizations === []) {
            $io->warning('No organizations qualified for import.');

            return Command::SUCCESS;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $truncate = !(bool) $input->getOption('no-truncate');

        $io->section('Import configuration');
        $io->listing([
            'Organizations: ' . implode(', ', $organizations),
            'Truncate target tables: ' . ($truncate ? 'yes' : 'no'),
            'Dry run (rollback): ' . ($dryRun ? 'yes' : 'no'),
        ]);

        $organizationIdByCode = [];
        $playerIdByOrgLegacy = [];
        $playerIdByIdentity = [];
        $seriesIdByOrgLegacy = [];
        $tournamentIdByOrgLegacy = [];
        $counters = [];

        $this->postgresConnection->beginTransaction();

        try {
            if ($truncate) {
                $this->truncateTargetSchema();
            }

            foreach ($organizations as $organizationCode) {
                $organizationId = $this->insertOrganization($organizationCode);
                $organizationIdByCode[$organizationCode] = $organizationId;
            }

            foreach ($organizations as $organizationCode) {
                $organizationId = $organizationIdByCode[$organizationCode];
                $io->writeln(sprintf('Importing %s...', $organizationCode));

                $playerIdByOrgLegacy[$organizationCode] = $this->importPlayers(
                    $organizationCode,
                    $organizationId,
                    $playerIdByIdentity,
                    $counters,
                );
                $seriesIdByOrgLegacy[$organizationCode] = $this->importSeries($organizationCode, $organizationId, $counters);
                $tournamentIdByOrgLegacy[$organizationCode] = $this->importTournaments(
                    $organizationCode,
                    $organizationId,
                    $playerIdByOrgLegacy[$organizationCode],
                    $seriesIdByOrgLegacy[$organizationCode],
                    $counters,
                );

                $this->importPlaySummaries(
                    $organizationCode,
                    $organizationId,
                    $playerIdByOrgLegacy[$organizationCode],
                    $counters,
                );
                $this->importRankings(
                    $organizationCode,
                    $organizationId,
                    $playerIdByOrgLegacy[$organizationCode],
                    $tournamentIdByOrgLegacy[$organizationCode],
                    $counters,
                );
                $this->importTournamentResults(
                    $organizationCode,
                    $organizationId,
                    $playerIdByOrgLegacy[$organizationCode],
                    $tournamentIdByOrgLegacy[$organizationCode],
                    $counters,
                );
                $this->importTournamentGames(
                    $organizationCode,
                    $organizationId,
                    $playerIdByOrgLegacy[$organizationCode],
                    $tournamentIdByOrgLegacy[$organizationCode],
                    $counters,
                );
                $this->importTextResources($organizationCode, $organizationId, $counters);
                $this->importGameRecords(
                    $organizationCode,
                    $organizationId,
                    $playerIdByOrgLegacy[$organizationCode],
                    $tournamentIdByOrgLegacy[$organizationCode],
                    $organizationTables[$organizationCode],
                    $counters,
                );
            }

            if ($dryRun) {
                $this->postgresConnection->rollBack();
                $io->warning('Dry-run mode enabled: transaction rolled back.');
            } else {
                $this->postgresConnection->commit();
            }
        } catch (\Throwable $exception) {
            if ($this->postgresConnection->isTransactionActive()) {
                $this->postgresConnection->rollBack();
            }

            $io->error(sprintf('Import failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        ksort($counters);
        $rows = [];
        foreach ($counters as $table => $count) {
            $rows[] = [$table, (string) $count];
        }

        $io->section('Imported rows');
        $io->table(['Table', 'Rows'], $rows);

        $io->success('Import finished successfully.');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function parseOrganizationsOption(mixed $option): ?array
    {
        if (!is_string($option)) {
            return [];
        }

        $trimmed = trim($option);
        if ($trimmed === '') {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $trimmed)), static fn (string $value): bool => $value !== ''));
        if ($parts === []) {
            return [];
        }

        $result = [];
        foreach ($parts as $part) {
            $code = strtoupper($part);
            if (!preg_match('/^[A-Z0-9_]+$/', $code)) {
                return null;
            }

            $result[$code] = true;
        }

        return array_keys($result);
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function discoverOrganizationTables(): array
    {
        $rows = $this->mysqlConnection->fetchFirstColumn(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'
        );

        $result = [];

        foreach ($rows as $tableName) {
            if (!is_string($tableName)) {
                continue;
            }

            if (!preg_match('/^([A-Z0-9_]+)(PLAYER|PLAYSUMM|RANKING|SERTOUR|TOURHH|TOURS|TOURWYN|TRESOURCE|GCG)$/', $tableName, $matches)) {
                continue;
            }

            $organizationCode = $matches[1];
            $suffix = $matches[2];

            $result[$organizationCode] ??= [];
            $result[$organizationCode][$suffix] = true;
        }

        return $result;
    }

    /**
     * @param array<string, array<string, true>> $organizationTables
     * @param list<string> $requestedOrganizations
     * @return list<string>
     */
    private function resolveOrganizationsToImport(array $organizationTables, array $requestedOrganizations, SymfonyStyle $io): array
    {
        $available = array_keys($organizationTables);
        sort($available);

        $targetOrganizations = $requestedOrganizations === [] ? $available : $requestedOrganizations;

        $result = [];
        foreach ($targetOrganizations as $organizationCode) {
            if (!isset($organizationTables[$organizationCode])) {
                $io->warning(sprintf('Skipping %s: no tables found for this organization.', $organizationCode));
                continue;
            }

            $missing = [];
            foreach (self::BASE_SUFFIXES as $suffix) {
                if (!isset($organizationTables[$organizationCode][$suffix])) {
                    $missing[] = $suffix;
                }
            }

            if ($missing !== []) {
                $io->warning(sprintf(
                    'Skipping %s: missing required tables: %s',
                    $organizationCode,
                    implode(', ', $missing),
                ));
                continue;
            }

            $result[] = $organizationCode;
        }

        sort($result);

        return $result;
    }

    private function truncateTargetSchema(): void
    {
        $this->postgresConnection->executeStatement(
            'TRUNCATE TABLE '
            . 'game_record, tournament_game, tournament_result, ranking, play_summary, tournament, series, player_organization, player, text_resource, organization '
            . 'RESTART IDENTITY CASCADE'
        );
    }

    private function insertOrganization(string $organizationCode): int
    {
        return (int) $this->postgresConnection->fetchOne(
            'INSERT INTO organization (code, name) VALUES (:code, :name) RETURNING id',
            [
                'code' => $organizationCode,
                'name' => $organizationCode,
            ],
        );
    }

    /**
     * @param array<string, int> $playerIdByIdentity
     * @param array<string, int> $counters
     * @return array<int, int>
     */
    private function importPlayers(
        string $organizationCode,
        int $organizationId,
        array &$playerIdByIdentity,
        array &$counters,
    ): array {
        $map = [];
        $table = $this->sourceTable($organizationCode, 'PLAYER');

        foreach ($this->mysqlConnection->iterateAssociative("SELECT id, name_show, name_alph, utype, cached FROM {$table} ORDER BY id") as $row) {
            $nameShow = $this->normalizeNullableString($row['name_show']);
            $nameAlph = $this->normalizeNullableString($row['name_alph']);
            $utype = $this->normalizeNullableString($row['utype']);
            $cached = $this->normalizeNullableString($row['cached']);
            $playerKey = $this->playerIdentityKey($nameShow, $nameAlph);

            if (!isset($playerIdByIdentity[$playerKey])) {
                $playerIdByIdentity[$playerKey] = (int) $this->postgresConnection->fetchOne(
                    'INSERT INTO player (name_show, name_alph, utype, cached) VALUES (:nameShow, :nameAlph, :utype, :cached) RETURNING id',
                    [
                        'nameShow' => $nameShow,
                        'nameAlph' => $nameAlph,
                        'utype' => $utype,
                        'cached' => $cached,
                    ],
                );

                $this->increment($counters, 'player');
            } else {
                $this->postgresConnection->executeStatement(
                    'UPDATE player
                     SET utype = COALESCE(utype, :utype),
                         cached = COALESCE(cached, :cached)
                     WHERE id = :playerId',
                    [
                        'utype' => $utype,
                        'cached' => $cached,
                        'playerId' => $playerIdByIdentity[$playerKey],
                    ],
                );
            }

            $playerId = $playerIdByIdentity[$playerKey];

            $insertedAssociationCount = $this->postgresConnection->executeStatement(
                'INSERT INTO player_organization (player_id, organization_id)
                 VALUES (:playerId, :organizationId)
                 ON CONFLICT DO NOTHING',
                [
                    'playerId' => $playerId,
                    'organizationId' => $organizationId,
                ],
            );

            $legacyPlayerId = (int) $row['id'];
            $map[$legacyPlayerId] = $playerId;

            if ($insertedAssociationCount > 0) {
                $this->increment($counters, 'player_organization');
            }
        }

        return $map;
    }

    /**
     * @param array<string, int> $counters
     * @return array<int, int>
     */
    private function importSeries(string $organizationCode, int $organizationId, array &$counters): array
    {
        $map = [];
        $table = $this->sourceTable($organizationCode, 'SERTOUR');

        foreach ($this->mysqlConnection->iterateAssociative("SELECT id, name FROM {$table} ORDER BY id") as $row) {
            $seriesId = (int) $this->postgresConnection->fetchOne(
                'INSERT INTO series (organization_id, legacy_id, name) VALUES (:organizationId, :legacyId, :name) RETURNING id',
                [
                    'organizationId' => $organizationId,
                    'legacyId' => (int) $row['id'],
                    'name' => $this->normalizeNullableString($row['name']),
                ],
            );

            $map[(int) $row['id']] = $seriesId;
            $this->increment($counters, 'series');
        }

        return $map;
    }

    /**
     * @param array<int, int> $playerIdByLegacy
     * @param array<int, int> $seriesIdByLegacy
     * @param array<string, int> $counters
     * @return array<int, int>
     */
    private function importTournaments(
        string $organizationCode,
        int $organizationId,
        array $playerIdByLegacy,
        array $seriesIdByLegacy,
        array &$counters,
    ): array {
        $map = [];
        $table = $this->sourceTable($organizationCode, 'TOURS');

        foreach ($this->mysqlConnection->iterateAssociative('SELECT * FROM ' . $table . ' ORDER BY id') as $row) {
            $legacyWinnerPlayerId = $this->toNullableInt($row['winner']);
            $legacySeriesId = $this->toNullableInt($row['sertour']);
            $legacyTournamentId = (int) $row['id'];

            $tournamentId = (int) $this->postgresConnection->fetchOne(
                'INSERT INTO tournament (
                    organization_id,
                    legacy_id,
                    dt,
                    name,
                    fullname,
                    winner_player_id,
                    legacy_winner_player_id,
                    trank,
                    players_count,
                    rounds,
                    rrecreated,
                    team,
                    mcategory,
                    wksum,
                    series_id,
                    legacy_series_id,
                    start_round,
                    referee,
                    place,
                    organizer,
                    urlid
                ) VALUES (
                    :organizationId,
                    :legacyId,
                    :dt,
                    :name,
                    :fullname,
                    :winnerPlayerId,
                    :legacyWinnerPlayerId,
                    :trank,
                    :playersCount,
                    :rounds,
                    :rrecreated,
                    :team,
                    :mcategory,
                    :wksum,
                    :seriesId,
                    :legacySeriesId,
                    :startRound,
                    :referee,
                    :place,
                    :organizer,
                    :urlid
                ) RETURNING id',
                [
                    'organizationId' => $organizationId,
                    'legacyId' => $legacyTournamentId,
                    'dt' => (int) $row['dt'],
                    'name' => $this->normalizeNullableString($row['name']),
                    'fullname' => $this->normalizeNullableString($row['fullname']),
                    'winnerPlayerId' => $legacyWinnerPlayerId !== null ? ($playerIdByLegacy[$legacyWinnerPlayerId] ?? null) : null,
                    'legacyWinnerPlayerId' => $legacyWinnerPlayerId,
                    'trank' => $this->toNullableFloat($row['trank']),
                    'playersCount' => $this->toNullableInt($row['players']),
                    'rounds' => $this->toNullableInt($row['rounds']),
                    'rrecreated' => $this->normalizeNullableString($row['rrecreated']),
                    'team' => $this->normalizeNullableString($row['team']),
                    'mcategory' => $this->toNullableInt($row['mcategory']),
                    'wksum' => $this->toNullableFloat($row['wksum']),
                    'seriesId' => $legacySeriesId !== null ? ($seriesIdByLegacy[$legacySeriesId] ?? null) : null,
                    'legacySeriesId' => $legacySeriesId,
                    'startRound' => $this->toNullableInt($row['start']),
                    'referee' => $this->normalizeNullableString($row['referee']),
                    'place' => $this->normalizeNullableString($row['place']),
                    'organizer' => $this->normalizeNullableString($row['organizer']),
                    'urlid' => $this->toNullableInt($row['urlid']),
                ],
            );

            $map[$legacyTournamentId] = $tournamentId;
            $this->increment($counters, 'tournament');
        }

        return $map;
    }

    /**
     * @param array<int, int> $playerIdByLegacy
     * @param array<string, int> $counters
     */
    private function importPlaySummaries(
        string $organizationCode,
        int $organizationId,
        array $playerIdByLegacy,
        array &$counters,
    ): void {
        $table = $this->sourceTable($organizationCode, 'PLAYSUMM');

        foreach ($this->mysqlConnection->iterateAssociative('SELECT * FROM ' . $table . ' ORDER BY player, stype') as $row) {
            $legacyPlayerId = (int) $row['player'];

            $this->postgresConnection->insert('play_summary', [
                'organization_id' => $organizationId,
                'player_id' => $playerIdByLegacy[$legacyPlayerId] ?? null,
                'legacy_player_id' => $legacyPlayerId,
                'stype' => (int) $row['stype'],
                'gwin' => $this->toNullableInt($row['gwin']),
                'glost' => $this->toNullableInt($row['glost']),
                'gdraw' => $this->toNullableInt($row['gdraw']),
                'games' => $this->toNullableInt($row['games']),
                'gwinnw' => $this->toNullableInt($row['gwinnw']),
                'glostnw' => $this->toNullableInt($row['glostnw']),
                'gdrawnw' => $this->toNullableInt($row['gdrawnw']),
                'gamesnw' => $this->toNullableInt($row['gamesnw']),
                'gwin130' => $this->toNullableInt($row['gwin130']),
                'games130' => $this->toNullableInt($row['games130']),
                'gwin110' => $this->toNullableInt($row['gwin110']),
                'games110' => $this->toNullableInt($row['games110']),
                'gwin100' => $this->toNullableInt($row['gwin100']),
                'games100' => $this->toNullableInt($row['games100']),
                'over350' => $this->toNullableInt($row['over350']),
                'over400' => $this->toNullableInt($row['over400']),
                'over500' => $this->toNullableInt($row['over500']),
                'over600' => $this->toNullableInt($row['over600']),
                'grank' => $this->toNullableFloat($row['grank']),
                'points' => $this->toNullableFloat($row['points']),
                'pointo' => $this->toNullableFloat($row['pointo']),
                'pointsw' => $this->toNullableFloat($row['pointsw']),
                'pointow' => $this->toNullableFloat($row['pointow']),
                'pointsl' => $this->toNullableFloat($row['pointsl']),
                'pointol' => $this->toNullableFloat($row['pointol']),
            ]);

            $this->increment($counters, 'play_summary');
        }
    }

    /**
     * @param array<int, int> $playerIdByLegacy
     * @param array<int, int> $tournamentIdByLegacy
     * @param array<string, int> $counters
     */
    private function importRankings(
        string $organizationCode,
        int $organizationId,
        array $playerIdByLegacy,
        array $tournamentIdByLegacy,
        array &$counters,
    ): void {
        $table = $this->sourceTable($organizationCode, 'RANKING');

        foreach ($this->mysqlConnection->iterateAssociative('SELECT * FROM ' . $table . ' ORDER BY turniej, pos, player') as $row) {
            $legacyPlayerId = (int) $row['player'];
            $legacyTournamentId = (int) $row['turniej'];

            $this->postgresConnection->insert('ranking', [
                'organization_id' => $organizationId,
                'rtype' => (string) $row['rtype'],
                'player_id' => $playerIdByLegacy[$legacyPlayerId] ?? null,
                'tournament_id' => $tournamentIdByLegacy[$legacyTournamentId] ?? null,
                'legacy_player_id' => $legacyPlayerId,
                'legacy_tournament_id' => $legacyTournamentId,
                'position' => (int) $row['pos'],
                'rank' => (float) $row['rank'],
                'games' => (int) $row['games'],
            ]);

            $this->increment($counters, 'ranking');
        }
    }

    /**
     * @param array<int, int> $playerIdByLegacy
     * @param array<int, int> $tournamentIdByLegacy
     * @param array<string, int> $counters
     */
    private function importTournamentResults(
        string $organizationCode,
        int $organizationId,
        array $playerIdByLegacy,
        array $tournamentIdByLegacy,
        array &$counters,
    ): void {
        $table = $this->sourceTable($organizationCode, 'TOURWYN');

        foreach ($this->mysqlConnection->iterateAssociative('SELECT * FROM ' . $table . ' ORDER BY turniej, place, player') as $row) {
            $legacyPlayerId = (int) $row['player'];
            $legacyTournamentId = (int) $row['turniej'];

            $this->postgresConnection->insert('tournament_result', [
                'organization_id' => $organizationId,
                'tournament_id' => $tournamentIdByLegacy[$legacyTournamentId] ?? null,
                'player_id' => $playerIdByLegacy[$legacyPlayerId] ?? null,
                'legacy_tournament_id' => $legacyTournamentId,
                'legacy_player_id' => $legacyPlayerId,
                'place' => (int) $row['place'],
                'gwin' => $this->toNullableInt($row['gwin']),
                'glost' => $this->toNullableInt($row['glost']),
                'gdraw' => $this->toNullableInt($row['gdraw']),
                'games' => $this->toNullableInt($row['games']),
                'trank' => $this->toNullableFloat($row['trank']),
                'brank' => $this->toNullableFloat($row['brank']),
                'points' => $this->toNullableFloat($row['points']),
                'pointo' => $this->toNullableFloat($row['pointo']),
                'hostgames' => $this->toNullableInt($row['hostgames']),
                'hostwin' => $this->toNullableInt($row['hostwin']),
                'masters' => $this->toNullableInt($row['masters']),
            ]);

            $this->increment($counters, 'tournament_result');
        }
    }

    /**
     * @param array<int, int> $playerIdByLegacy
     * @param array<int, int> $tournamentIdByLegacy
     * @param array<string, int> $counters
     */
    private function importTournamentGames(
        string $organizationCode,
        int $organizationId,
        array $playerIdByLegacy,
        array $tournamentIdByLegacy,
        array &$counters,
    ): void {
        $table = $this->sourceTable($organizationCode, 'TOURHH');

        foreach ($this->mysqlConnection->iterateAssociative('SELECT * FROM ' . $table . ' ORDER BY turniej, runda, player1, player2') as $row) {
            $legacyTournamentId = (int) $row['turniej'];
            $legacyPlayer1Id = (int) $row['player1'];
            $legacyPlayer2Id = (int) $row['player2'];

            $this->postgresConnection->insert('tournament_game', [
                'organization_id' => $organizationId,
                'tournament_id' => $tournamentIdByLegacy[$legacyTournamentId] ?? null,
                'player1_id' => $playerIdByLegacy[$legacyPlayer1Id] ?? null,
                'player2_id' => $playerIdByLegacy[$legacyPlayer2Id] ?? null,
                'legacy_tournament_id' => $legacyTournamentId,
                'round_no' => (int) $row['runda'],
                'table_no' => $this->toNullableInt($row['stol']),
                'legacy_player1_id' => $legacyPlayer1Id,
                'legacy_player2_id' => $legacyPlayer2Id,
                'result1' => (int) $row['result1'],
                'result2' => (int) $row['result2'],
                'ranko' => $this->toNullableInt($row['ranko']),
                'host' => $this->toNullableInt($row['host']),
            ]);

            $this->increment($counters, 'tournament_game');
        }
    }

    /**
     * @param array<string, int> $counters
     */
    private function importTextResources(string $organizationCode, int $organizationId, array &$counters): void
    {
        $table = $this->sourceTable($organizationCode, 'TRESOURCE');

        foreach ($this->mysqlConnection->iterateAssociative('SELECT * FROM ' . $table . ' ORDER BY type, id') as $row) {
            $this->postgresConnection->insert('text_resource', [
                'organization_id' => $organizationId,
                'resource_type' => (int) $row['type'],
                'legacy_id' => (int) $row['id'],
                'data' => (string) $row['data'],
            ]);

            $this->increment($counters, 'text_resource');
        }
    }

    /**
     * @param array<int, int> $playerIdByLegacy
     * @param array<int, int> $tournamentIdByLegacy
     * @param array<string, true> $organizationTables
     * @param array<string, int> $counters
     */
    private function importGameRecords(
        string $organizationCode,
        int $organizationId,
        array $playerIdByLegacy,
        array $tournamentIdByLegacy,
        array $organizationTables,
        array &$counters,
    ): void {
        if (!isset($organizationTables['GCG'])) {
            return;
        }

        $table = $this->sourceTable($organizationCode, 'GCG');

        foreach ($this->mysqlConnection->iterateAssociative('SELECT tour, `round`, player1, data, updated FROM ' . $table . ' ORDER BY tour, `round`, player1') as $row) {
            $legacyTournamentId = (int) $row['tour'];
            $legacyPlayer1Id = (int) $row['player1'];
            $updatedAt = new \DateTimeImmutable((string) $row['updated']);

            $this->postgresConnection->insert('game_record', [
                'organization_id' => $organizationId,
                'tournament_id' => $tournamentIdByLegacy[$legacyTournamentId] ?? null,
                'player1_id' => $playerIdByLegacy[$legacyPlayer1Id] ?? null,
                'legacy_tournament_id' => $legacyTournamentId,
                'round_no' => (int) $row['round'],
                'legacy_player1_id' => $legacyPlayer1Id,
                'data' => (string) $row['data'],
                'updated_at' => $updatedAt->format('Y-m-d H:i:s'),
            ]);

            $this->increment($counters, 'game_record');
        }
    }

    private function sourceTable(string $organizationCode, string $suffix): string
    {
        if (!preg_match('/^[A-Z0-9_]+$/', $organizationCode)) {
            throw new \InvalidArgumentException(sprintf('Unsafe organization code: %s', $organizationCode));
        }

        return sprintf('`%s%s`', $organizationCode, $suffix);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $stringValue = rtrim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        return $stringValue;
    }

    private function playerIdentityKey(?string $nameShow, ?string $nameAlph): string
    {
        return json_encode([$nameShow, $nameAlph], JSON_THROW_ON_ERROR);
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param array<string, int> $counters
     */
    private function increment(array &$counters, string $name): void
    {
        $counters[$name] = ($counters[$name] ?? 0) + 1;
    }
}
