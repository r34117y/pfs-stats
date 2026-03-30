<?php

declare(strict_types=1);

namespace App\Command\PostgresImport;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
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
    private const int INSERT_BATCH_SIZE = 250;
    private const int SOURCE_BATCH_SIZE = 1000;

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
        private Connection $postgresConnection,
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $mysqlConnection,
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

        $truncate = !(bool) $input->getOption('no-truncate');

        $io->section('Import configuration');
        $io->listing([
            'Organizations: ' . implode(', ', $organizations),
            'Truncate target tables: ' . ($truncate ? 'yes' : 'no'),
        ]);

        $counters = [];

        try {
            $this->optimizeConnectionsForImport();
            $this->createImportTempTables();

            if ($truncate) {
                $this->postgresConnection->beginTransaction();

                try {
                    $this->truncateTargetSchema();
                    $this->postgresConnection->commit();
                } catch (\Throwable $exception) {
                    if ($this->postgresConnection->isTransactionActive()) {
                        $this->postgresConnection->rollBack();
                    }

                    throw $exception;
                }
            }

            foreach ($organizations as $organizationCode) {
                $io->writeln(sprintf('Importing %s...', $organizationCode));
                $this->importOrganization(
                    $organizationCode,
                    $organizationTables[$organizationCode],
                    $counters,
                );
                gc_collect_cycles();
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

    private function optimizeConnectionsForImport(): void
    {
        $this->postgresConnection = $this->recreateConnectionWithoutMiddlewares($this->postgresConnection);
        $this->mysqlConnection = $this->recreateConnectionWithoutMiddlewares($this->mysqlConnection);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed>|null $mutateParams
     */
    private function recreateConnectionWithoutMiddlewares(Connection $connection, ?callable $mutateParams = null): Connection
    {
        $params = $connection->getParams();
        if ($mutateParams !== null) {
            $params = $mutateParams($params);
        }

        $configuration = new Configuration();
        $configuration->setMiddlewares([]);
        $configuration->setSQLLogger(null);

        return DriverManager::getConnection($params, $configuration);
    }

    /**
     * @param array<string, true> $organizationTables
     * @param array<string, int> $counters
     */
    private function importOrganization(
        string $organizationCode,
        array $organizationTables,
        array &$counters,
    ): void {
        $this->postgresConnection->beginTransaction();

        try {
            $organizationId = $this->insertOrganization($organizationCode);
            $this->resetOrganizationImportTempTables();
            $this->importPlayers(
                $organizationCode,
                $organizationId,
                $counters,
            );
            $this->importSeries($organizationCode, $organizationId, $counters);
            $this->importTournaments(
                $organizationCode,
                $organizationId,
                $counters,
            );

            $this->importPlaySummaries(
                $organizationCode,
                $organizationId,
                $counters,
            );
            $this->importRankings(
                $organizationCode,
                $organizationId,
                $counters,
            );
            $this->importTournamentResults(
                $organizationCode,
                $organizationId,
                $counters,
            );
            $this->importTournamentGames(
                $organizationCode,
                $organizationId,
                $counters,
            );
            $this->importGcgRecords(
                $organizationCode,
                $organizationId,
                $organizationTables,
                $counters,
            );
            $this->importTextResources($organizationCode, $organizationId, $counters);

            $this->postgresConnection->commit();
        } catch (\Throwable $exception) {
            if ($this->postgresConnection->isTransactionActive()) {
                $this->postgresConnection->rollBack();
            }

            throw $exception;
        }
    }

    private function createImportTempTables(): void
    {
        $this->postgresConnection->executeStatement(
            'CREATE TEMP TABLE IF NOT EXISTS import_player_identity_map (
                identity_key TEXT PRIMARY KEY,
                player_id INT NOT NULL
            )'
        );
        $this->postgresConnection->executeStatement(
            'CREATE TEMP TABLE IF NOT EXISTS import_player_legacy_map (
                legacy_player_id INT PRIMARY KEY,
                player_id INT NOT NULL
            )'
        );
        $this->postgresConnection->executeStatement(
            'CREATE TEMP TABLE IF NOT EXISTS import_series_legacy_map (
                legacy_series_id INT PRIMARY KEY,
                series_id INT NOT NULL
            )'
        );
        $this->postgresConnection->executeStatement(
            'CREATE TEMP TABLE IF NOT EXISTS import_tournament_legacy_map (
                legacy_tournament_id INT PRIMARY KEY,
                tournament_id INT NOT NULL
            )'
        );
    }

    private function resetOrganizationImportTempTables(): void
    {
        $this->postgresConnection->executeStatement(
            'TRUNCATE import_player_legacy_map, import_series_legacy_map, import_tournament_legacy_map'
        );
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
            . 'tournament_game, tournament_result, ranking, play_summary, tournament, series, player_organization, player, text_resource, organization '
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
     * @param array<string, int> $counters
     */
    private function importPlayers(
        string $organizationCode,
        int $organizationId,
        array &$counters,
    ): void {
        $table = $this->sourceTable($organizationCode, 'PLAYER');
        $cursor = [];

        while (true) {
            $chunk = $this->fetchSourceRowsChunk($table, 'id, name_show, name_alph', ['id'], $cursor);
            if ($chunk === []) {
                return;
            }

            foreach ($chunk as $row) {
                $nameShow = $this->normalizeNullableString($row['name_show']);
                $nameAlph = $this->normalizeNullableString($row['name_alph']);
                $playerKey = $this->playerIdentityKey($nameShow, $nameAlph);
                $playerId = $this->resolveImportedPlayerId($playerKey, $nameShow, $nameAlph, $counters);

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
                $this->postgresConnection->insert('import_player_legacy_map', [
                    'legacy_player_id' => $legacyPlayerId,
                    'player_id' => $playerId,
                ]);

                if ($insertedAssociationCount > 0) {
                    $this->increment($counters, 'player_organization');
                }
            }

            $cursor = $this->sourceChunkCursor($chunk, ['id']);
        }
    }

    /**
     * @param array<string, int> $counters
     */
    private function resolveImportedPlayerId(
        string $playerKey,
        ?string $nameShow,
        ?string $nameAlph,
        array &$counters,
    ): int {
        $cachedPlayerId = $this->postgresConnection->fetchOne(
            'SELECT player_id
             FROM import_player_identity_map
             WHERE identity_key = :identityKey',
            ['identityKey' => $playerKey],
        );

        if ($cachedPlayerId !== false) {
            return (int) $cachedPlayerId;
        }

        $playerId = (int) $this->postgresConnection->fetchOne(
            'INSERT INTO player (name_show, name_alph) VALUES (:nameShow, :nameAlph) RETURNING id',
            [
                'nameShow' => $nameShow,
                'nameAlph' => $nameAlph,
            ],
        );

        $this->postgresConnection->insert('import_player_identity_map', [
            'identity_key' => $playerKey,
            'player_id' => $playerId,
        ]);

        $this->increment($counters, 'player');

        return $playerId;
    }

    private function resolveMappedPlayerId(int $legacyPlayerId): ?int
    {
        $playerId = $this->postgresConnection->fetchOne(
            'SELECT player_id
             FROM import_player_legacy_map
             WHERE legacy_player_id = :legacyPlayerId',
            ['legacyPlayerId' => $legacyPlayerId],
        );

        return $playerId !== false ? (int) $playerId : null;
    }

    private function resolveMappedSeriesId(int $legacySeriesId): ?int
    {
        $seriesId = $this->postgresConnection->fetchOne(
            'SELECT series_id
             FROM import_series_legacy_map
             WHERE legacy_series_id = :legacySeriesId',
            ['legacySeriesId' => $legacySeriesId],
        );

        return $seriesId !== false ? (int) $seriesId : null;
    }

    private function resolveMappedTournamentId(int $legacyTournamentId): ?int
    {
        $tournamentId = $this->postgresConnection->fetchOne(
            'SELECT tournament_id
             FROM import_tournament_legacy_map
             WHERE legacy_tournament_id = :legacyTournamentId',
            ['legacyTournamentId' => $legacyTournamentId],
        );

        return $tournamentId !== false ? (int) $tournamentId : null;
    }

    /**
     * @param array<string, int> $counters
     */
    private function importSeries(string $organizationCode, int $organizationId, array &$counters): void
    {
        $table = $this->sourceTable($organizationCode, 'SERTOUR');
        $cursor = [];

        while (true) {
            $chunk = $this->fetchSourceRowsChunk($table, 'id, name', ['id'], $cursor);
            if ($chunk === []) {
                return;
            }

            foreach ($chunk as $row) {
                $seriesId = (int) $this->postgresConnection->fetchOne(
                    'INSERT INTO series (organization_id, legacy_id, name) VALUES (:organizationId, :legacyId, :name) RETURNING id',
                    [
                        'organizationId' => $organizationId,
                        'legacyId' => (int) $row['id'],
                        'name' => $this->normalizeNullableString($row['name']),
                    ],
                );

                $this->postgresConnection->insert('import_series_legacy_map', [
                    'legacy_series_id' => (int) $row['id'],
                    'series_id' => $seriesId,
                ]);
                $this->increment($counters, 'series');
            }

            $cursor = $this->sourceChunkCursor($chunk, ['id']);
        }
    }

    /**
     * @param array<string, int> $counters
     */
    private function importTournaments(
        string $organizationCode,
        int $organizationId,
        array &$counters,
    ): void {
        $table = $this->sourceTable($organizationCode, 'TOURS');
        $cursor = [];

        while (true) {
            $chunk = $this->fetchSourceRowsChunk($table, '*', ['id'], $cursor);
            if ($chunk === []) {
                return;
            }

            foreach ($chunk as $row) {
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
                        'winnerPlayerId' => $legacyWinnerPlayerId !== null ? $this->resolveMappedPlayerId($legacyWinnerPlayerId) : null,
                        'legacyWinnerPlayerId' => $legacyWinnerPlayerId,
                        'trank' => $this->toNullableFloat($row['trank']),
                        'playersCount' => $this->toNullableInt($row['players']),
                        'rounds' => $this->toNullableInt($row['rounds']),
                        'rrecreated' => $this->normalizeNullableString($row['rrecreated']),
                        'team' => $this->normalizeNullableString($row['team']),
                        'mcategory' => $this->toNullableInt($row['mcategory']),
                        'wksum' => $this->toNullableFloat($row['wksum']),
                        'seriesId' => $legacySeriesId !== null ? $this->resolveMappedSeriesId($legacySeriesId) : null,
                        'legacySeriesId' => $legacySeriesId,
                        'startRound' => $this->toNullableInt($row['start']),
                        'referee' => $this->normalizeNullableString($row['referee']),
                        'place' => $this->normalizeNullableString($row['place']),
                        'organizer' => $this->normalizeNullableString($row['organizer']),
                        'urlid' => $this->toNullableInt($row['urlid']),
                    ],
                );

                $this->postgresConnection->insert('import_tournament_legacy_map', [
                    'legacy_tournament_id' => $legacyTournamentId,
                    'tournament_id' => $tournamentId,
                ]);
                $this->increment($counters, 'tournament');
            }

            $cursor = $this->sourceChunkCursor($chunk, ['id']);
        }
    }

    /**
     * @param array<string, int> $counters
     */
    private function importPlaySummaries(
        string $organizationCode,
        int $organizationId,
        array &$counters,
    ): void {
        $table = $this->sourceTable($organizationCode, 'PLAYSUMM');
        $cursor = [];

        while (true) {
            $chunk = $this->fetchSourceRowsChunk($table, '*', ['player', 'stype'], $cursor);
            if ($chunk === []) {
                return;
            }

            $rows = [];
            foreach ($chunk as $row) {
                $legacyPlayerId = (int) $row['player'];

                $rows[] = [
                    'organization_id' => $organizationId,
                    'player_id' => $this->resolveMappedPlayerId($legacyPlayerId),
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
                ];
            }

            if ($rows !== []) {
                $this->flushBatchInsert('play_summary', $rows);
                $counters['play_summary'] = ($counters['play_summary'] ?? 0) + count($rows);
            }

            $cursor = $this->sourceChunkCursor($chunk, ['player', 'stype']);
        }
    }

    /**
     * @param array<string, int> $counters
     */
    private function importRankings(
        string $organizationCode,
        int $organizationId,
        array &$counters,
    ): void {
        $table = $this->sourceTable($organizationCode, 'RANKING');
        $cursor = [];

        while (true) {
            $chunk = $this->fetchSourceRowsChunk($table, '*', ['turniej', 'pos', 'player'], $cursor);
            if ($chunk === []) {
                return;
            }

            $rows = [];
            foreach ($chunk as $row) {
                $legacyPlayerId = (int) $row['player'];
                $legacyTournamentId = (int) $row['turniej'];

                $rows[] = [
                    'organization_id' => $organizationId,
                    'rtype' => (string) $row['rtype'],
                    'player_id' => $this->resolveMappedPlayerId($legacyPlayerId),
                    'tournament_id' => $this->resolveMappedTournamentId($legacyTournamentId),
                    'legacy_player_id' => $legacyPlayerId,
                    'legacy_tournament_id' => $legacyTournamentId,
                    'position' => (int) $row['pos'],
                    'rank' => (float) $row['rank'],
                    'games' => (int) $row['games'],
                ];
            }

            if ($rows !== []) {
                $this->flushBatchInsert('ranking', $rows);
                $counters['ranking'] = ($counters['ranking'] ?? 0) + count($rows);
            }

            $cursor = $this->sourceChunkCursor($chunk, ['turniej', 'pos', 'player']);
        }
    }

    /**
     * @param array<string, int> $counters
     */
    private function importTournamentResults(
        string $organizationCode,
        int $organizationId,
        array &$counters,
    ): void {
        $table = $this->sourceTable($organizationCode, 'TOURWYN');
        $cursor = [];

        while (true) {
            $chunk = $this->fetchSourceRowsChunk($table, '*', ['turniej', 'place', 'player'], $cursor);
            if ($chunk === []) {
                return;
            }

            $rows = [];
            foreach ($chunk as $row) {
                $legacyPlayerId = (int) $row['player'];
                $legacyTournamentId = (int) $row['turniej'];

                $rows[] = [
                    'organization_id' => $organizationId,
                    'tournament_id' => $this->resolveMappedTournamentId($legacyTournamentId),
                    'player_id' => $this->resolveMappedPlayerId($legacyPlayerId),
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
                ];
            }

            if ($rows !== []) {
                $this->flushBatchInsert('tournament_result', $rows);
                $counters['tournament_result'] = ($counters['tournament_result'] ?? 0) + count($rows);
            }

            $cursor = $this->sourceChunkCursor($chunk, ['turniej', 'place', 'player']);
        }
    }

    /**
     * @param array<string, int> $counters
     */
    private function importTournamentGames(
        string $organizationCode,
        int $organizationId,
        array &$counters,
    ): void {
        $table = $this->sourceTable($organizationCode, 'TOURHH');
        $cursor = [];

        while (true) {
            $chunk = $this->fetchSourceRowsChunk($table, '*', ['turniej', 'runda', 'player1', 'player2'], $cursor);
            if ($chunk === []) {
                return;
            }

            $rows = [];
            foreach ($chunk as $row) {
                $legacyTournamentId = (int) $row['turniej'];
                $legacyPlayer1Id = (int) $row['player1'];
                $legacyPlayer2Id = (int) $row['player2'];

                $rows[] = [
                    'organization_id' => $organizationId,
                    'tournament_id' => $this->resolveMappedTournamentId($legacyTournamentId),
                    'player1_id' => $this->resolveMappedPlayerId($legacyPlayer1Id),
                    'player2_id' => $this->resolveMappedPlayerId($legacyPlayer2Id),
                    'legacy_tournament_id' => $legacyTournamentId,
                    'round_no' => (int) $row['runda'],
                    'table_no' => $this->toNullableInt($row['stol']),
                    'legacy_player1_id' => $legacyPlayer1Id,
                    'legacy_player2_id' => $legacyPlayer2Id,
                    'result1' => (int) $row['result1'],
                    'result2' => (int) $row['result2'],
                    'ranko' => $this->toNullableInt($row['ranko']),
                    'host' => $this->toNullableInt($row['host']),
                    'gcg' => null,
                    'gcg_updated_at' => null,
                ];
            }

            if ($rows !== []) {
                $this->flushBatchInsert('tournament_game', $rows);
                $counters['tournament_game'] = ($counters['tournament_game'] ?? 0) + count($rows);
            }

            $cursor = $this->sourceChunkCursor($chunk, ['turniej', 'runda', 'player1', 'player2']);
        }
    }

    /**
     * @param array<string, int> $counters
     */
    private function importTextResources(string $organizationCode, int $organizationId, array &$counters): void
    {
        $table = $this->sourceTable($organizationCode, 'TRESOURCE');
        $cursor = [];

        while (true) {
            $chunk = $this->fetchSourceRowsChunk($table, '*', ['type', 'id'], $cursor);
            if ($chunk === []) {
                return;
            }

            $rows = [];
            foreach ($chunk as $row) {
                $rows[] = [
                    'organization_id' => $organizationId,
                    'resource_type' => (int) $row['type'],
                    'legacy_id' => (int) $row['id'],
                    'data' => (string) $row['data'],
                ];
            }

            if ($rows !== []) {
                $this->flushBatchInsert('text_resource', $rows);
                $counters['text_resource'] = ($counters['text_resource'] ?? 0) + count($rows);
            }

            $cursor = $this->sourceChunkCursor($chunk, ['type', 'id']);
        }
    }

    /**
     * @param array<string, true> $organizationTables
     * @param array<string, int> $counters
     */
    private function importGcgRecords(
        string $organizationCode,
        int $organizationId,
        array $organizationTables,
        array &$counters,
    ): void {
        if (!isset($organizationTables['GCG'])) {
            return;
        }

        $table = $this->sourceTable($organizationCode, 'GCG');
        $cursor = [];

        while (true) {
            $chunk = $this->fetchSourceRowsChunk($table, 'tour, `round`, player1, data, updated', ['tour', 'round', 'player1'], $cursor);
            if ($chunk === []) {
                return;
            }

            foreach ($chunk as $row) {
                $legacyTournamentId = (int) $row['tour'];
                $legacyPlayer1Id = (int) $row['player1'];
                $updatedAt = new \DateTimeImmutable((string) $row['updated']);

                $updatedRows = $this->postgresConnection->executeStatement(
                    'UPDATE tournament_game
                     SET gcg = :gcg,
                         gcg_updated_at = :updatedAt
                     WHERE organization_id = :organizationId
                       AND legacy_tournament_id = :legacyTournamentId
                       AND round_no = :roundNo
                       AND legacy_player1_id = :legacyPlayer1Id',
                    [
                        'gcg' => (string) $row['data'],
                        'updatedAt' => $updatedAt->format('Y-m-d H:i:s'),
                        'organizationId' => $organizationId,
                        'legacyTournamentId' => $legacyTournamentId,
                        'roundNo' => (int) $row['round'],
                        'legacyPlayer1Id' => $legacyPlayer1Id,
                    ],
                );

                if ($updatedRows !== 1) {
                    throw new \RuntimeException(sprintf(
                        'Failed to match exactly one tournament_game row for %s GCG (%d, round %d, player1 %d); updated rows: %d.',
                        $organizationCode,
                        $legacyTournamentId,
                        (int) $row['round'],
                        $legacyPlayer1Id,
                        $updatedRows,
                    ));
                }

                $this->increment($counters, 'tournament_game_gcg');
            }

            $cursor = $this->sourceChunkCursor($chunk, ['tour', 'round', 'player1']);
        }
    }

    /**
     * @param list<string> $orderColumns
     * @param array<string, scalar> $cursor
     * @return list<array<string, mixed>>
     */
    private function fetchSourceRowsChunk(
        string $table,
        string $select,
        array $orderColumns,
        array $cursor,
    ): array {
        $sql = 'SELECT ' . $select . ' FROM ' . $table;
        $params = [];
        $whereClause = $this->buildSourceCursorWhereClause($orderColumns, $cursor, $params);
        if ($whereClause !== null) {
            $sql .= ' WHERE ' . $whereClause;
        }

        $sql .= ' ORDER BY ' . implode(', ', array_map(
            fn (string $column): string => $this->sourceColumn($column) . ' ASC',
            $orderColumns,
        ));
        $sql .= ' LIMIT ' . self::SOURCE_BATCH_SIZE;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->mysqlConnection->fetchAllAssociative($sql, $params);

        return $rows;
    }

    /**
     * @param list<string> $orderColumns
     * @param array<string, scalar> $cursor
     * @param array<string, scalar> $params
     */
    private function buildSourceCursorWhereClause(array $orderColumns, array $cursor, array &$params): ?string
    {
        if ($cursor === []) {
            return null;
        }

        $conditions = [];

        foreach ($orderColumns as $index => $column) {
            $paramName = sprintf('cursor_%d', $index);
            $params[$paramName] = $cursor[$column];

            $parts = [];
            for ($prefixIndex = 0; $prefixIndex < $index; $prefixIndex++) {
                $prefixColumn = $orderColumns[$prefixIndex];
                $parts[] = sprintf(
                    '%s = :cursor_%d',
                    $this->sourceColumn($prefixColumn),
                    $prefixIndex,
                );
            }

            $parts[] = sprintf('%s > :%s', $this->sourceColumn($column), $paramName);
            $conditions[] = '(' . implode(' AND ', $parts) . ')';
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $orderColumns
     * @return array<string, scalar>
     */
    private function sourceChunkCursor(array $rows, array $orderColumns): array
    {
        $lastRow = $rows[array_key_last($rows)];
        $cursor = [];

        foreach ($orderColumns as $column) {
            $value = $lastRow[$column];
            if (!is_scalar($value)) {
                throw new \RuntimeException(sprintf('Non-scalar cursor value encountered for column %s.', $column));
            }

            $cursor[$column] = $value;
        }

        return $cursor;
    }

    /**
     * @param non-empty-list<array<string, mixed>> $rows
     */
    private function flushBatchInsert(string $table, array $rows): void
    {
        $columns = array_keys($rows[0]);
        $params = [];
        $valueGroups = [];

        foreach ($rows as $row) {
            $placeholders = [];
            foreach ($columns as $column) {
                $params[] = $row[$column];
                $placeholders[] = '?';
            }

            $valueGroups[] = '(' . implode(', ', $placeholders) . ')';
        }

        $this->postgresConnection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $table,
                implode(', ', $columns),
                implode(', ', $valueGroups),
            ),
            $params,
        );
    }

    private function sourceColumn(string $column): string
    {
        if (!preg_match('/^[A-Z0-9_]+$/i', $column)) {
            throw new \InvalidArgumentException(sprintf('Unsafe source column: %s', $column));
        }

        return sprintf('`%s`', $column);
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
