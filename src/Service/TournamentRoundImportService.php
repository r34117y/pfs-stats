<?php

namespace App\Service;

use App\ApiResource\TournamentRound\TournamentRound;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final readonly class TournamentRoundImportService
{
    public const int AUDIT_RESOURCE_TYPE = 9001;
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private PfsNameNormalizer $nameNormalizer,
    ) {
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function import(TournamentRound $payload): int
    {
        $tournament = $this->requireArray($payload->tournament, 'tournament');
        $players = $this->requireList($payload->players, 'players');
        $results = $this->requireList($payload->results, 'results');
        $ranking = $this->requireList($payload->ranking, 'ranking');

        if ($players === []) {
            throw new BadRequestHttpException('Field "players" must not be empty.');
        }

        if ($ranking === []) {
            throw new BadRequestHttpException('Field "ranking" must not be empty.');
        }

        $organizationCode = $this->normalizeOrganizationCode($this->requireString($tournament, 'org', 'tournament'));
        $organization = $this->fetchOrganization($organizationCode);
        if ($organization === null) {
            throw new BadRequestHttpException(sprintf('Organization "%s" was not found.', $organizationCode));
        }

        $startDate = $this->parseDate($this->requireString($tournament, 'dateStart', 'tournament'), 'tournament.dateStart');
        $dateCode = (int) $startDate->format('Ymd');
        $fullname = $this->trimToLength($this->requireString($tournament, 'name', 'tournament'), 80);
        $city = $this->trimToLength($this->requireString($tournament, 'city', 'tournament'), 256);

        $existingTournamentId = $this->findExistingTournamentId($organization['id'], $dateCode, $fullname, $city);

        if ($existingTournamentId !== null) {
            throw new ConflictHttpException(sprintf('Tournament already exists with id %d.', $existingTournamentId));
        }

        return $this->connection->transactional(function (Connection $connection) use (
            $organization,
            $players,
            $results,
            $ranking,
            $dateCode,
            $startDate,
            $fullname,
            $city,
        ): int {
            $legacyTournamentId = $this->allocateTournamentLegacyId((int) $organization['id'], $dateCode);
            $catalog = $this->loadPlayerCatalog((int) $organization['id'], $legacyTournamentId);
            $nextLegacyPlayerId = $this->allocateNextLegacyPlayerId((int) $organization['id']);

            $resolvedPlayersByStartingPosition = [];
            $resolvedPlayersByName = [];
            $createdPlayerIds = [];

            foreach ($players as $index => $playerRow) {
                $context = sprintf('players[%d]', $index);
                $startingPosition = $this->requireInt($playerRow, 'startingPosition', $context);
                $place = $this->requireInt($playerRow, 'place', $context);
                $firstName = $this->requireString($playerRow, 'firstName', $context);
                $lastName = $this->requireString($playerRow, 'lastName', $context);
                $tournamentRank = max(100.0, $this->requireFloat($playerRow, 'tournamentRank', $context));
                $fullName = $this->buildPlayerName($firstName, $lastName);

                if (isset($resolvedPlayersByStartingPosition[$startingPosition])) {
                    throw new BadRequestHttpException(sprintf('Duplicate startingPosition %d in players payload.', $startingPosition));
                }

                $resolved = $this->resolvePlayer($catalog, $fullName, $tournamentRank);
                if ($resolved === null) {
                    $resolved = $this->createPlayer(
                        $connection,
                        (int) $organization['id'],
                        $fullName,
                        $nextLegacyPlayerId,
                    );
                    $catalog[] = $resolved;
                    $createdPlayerIds[] = $resolved["playerId"];
                    $nextLegacyPlayerId++;
                }

                if ($resolved['legacyPlayerId'] === null) {
                    $resolved['legacyPlayerId'] = $nextLegacyPlayerId;
                    $nextLegacyPlayerId++;
                }

                $resolvedPlayersByStartingPosition[$startingPosition] = [
                    'startingPosition' => $startingPosition,
                    'place' => $place,
                    'playerId' => $resolved['playerId'],
                    'legacyPlayerId' => $resolved['legacyPlayerId'],
                    'nameShow' => $resolved['nameShow'],
                    'nameAlph' => $resolved['nameAlph'],
                    'tournamentRank' => $tournamentRank,
                    'big' => $this->requireFloat($playerRow, 'big', $context),
                    'small' => $this->requireInt($playerRow, 'small', $context),
                    'scalps' => $this->requireFloat($playerRow, 'scalps', $context),
                    'difference' => $this->requireInt($playerRow, 'difference', $context),
                ];
                $resolvedPlayersByName[$resolved['nameShow']] = $resolvedPlayersByStartingPosition[$startingPosition];
            }

            $winner = $this->findWinner($resolvedPlayersByStartingPosition);
            $shortName = $this->buildShortTournamentName($startDate, $city);
            $rounds = $this->maxRound($results);

            $tournamentId = (int) $connection->fetchOne(
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
                    'organizationId' => (int) $organization['id'],
                    'legacyId' => $legacyTournamentId,
                    'dt' => $dateCode,
                    'name' => $shortName,
                    'fullname' => $fullname,
                    'winnerPlayerId' => $winner['playerId'],
                    'legacyWinnerPlayerId' => $winner['legacyPlayerId'],
                    'trank' => $this->averageTournamentRank($resolvedPlayersByStartingPosition),
                    'playersCount' => count($resolvedPlayersByStartingPosition),
                    'rounds' => $rounds,
                    'rrecreated' => '',
                    'team' => null,
                    'mcategory' => null,
                    'wksum' => 0.0,
                    'seriesId' => null,
                    'legacySeriesId' => null,
                    'startRound' => $dateCode,
                    'referee' => null,
                    'place' => $city,
                    'organizer' => null,
                    'urlid' => null,
                ],
            );

            $perPlayerStats = $this->buildPerPlayerStats($results, $resolvedPlayersByStartingPosition);

            foreach ($results as $index => $resultRow) {
                $context = sprintf('results[%d]', $index);
                $round = $this->requireInt($resultRow, 'round', $context);
                $table = $this->requireInt($resultRow, 'table', $context);
                $hostPosition = $this->requireInt($resultRow, 'host', $context);
                $guestPosition = $this->requireInt($resultRow, 'guest', $context);
                $score1 = $this->requireInt($resultRow, 'score1', $context);
                $score2 = $this->requireInt($resultRow, 'score2', $context);

                $host = $resolvedPlayersByStartingPosition[$hostPosition] ?? null;
                $guest = $resolvedPlayersByStartingPosition[$guestPosition] ?? null;
                if (($host === null || $guest === null) && !$this->isByeGame($score1, $score2)) {
                    throw new BadRequestHttpException(sprintf('Could not resolve game participants for %s.', $context));
                }

                $connection->insert('tournament_game', [
                    'organization_id' => (int) $organization['id'],
                    'tournament_id' => $tournamentId,
                    'player1_id' => $host['playerId'],
                    'player2_id' => $guest['playerId'] ?? null,
                    'legacy_tournament_id' => $legacyTournamentId,
                    'round_no' => $round,
                    'table_no' => $table,
                    'legacy_player1_id' => $host['legacyPlayerId'],
                    'legacy_player2_id' => $guest['legacyPlayerId'] ?? null,
                    'result1' => $score1,
                    'result2' => $score2,
                    'ranko' => (int) round($guest['tournamentRank'] ?? 100), // todo
                    'host' => 1,
                    'gcg' => null,
                    'gcg_updated_at' => null,
                ]);

                $connection->insert('tournament_game', [
                    'organization_id' => (int) $organization['id'],
                    'tournament_id' => $tournamentId,
                    'player1_id' => $guest['playerId'] ?? null,
                    'player2_id' => $host['playerId'],
                    'legacy_tournament_id' => $legacyTournamentId,
                    'round_no' => $round,
                    'table_no' => $table,
                    'legacy_player1_id' => $guest['legacyPlayerId'] ?? null,
                    'legacy_player2_id' => $host['legacyPlayerId'],
                    'result1' => $score2,
                    'result2' => $score1,
                    'ranko' => (int) round($host['tournamentRank']),
                    'host' => 2,
                    'gcg' => null,
                    'gcg_updated_at' => null,
                ]);
            }

            foreach ($resolvedPlayersByStartingPosition as $player) {
                $stats = $perPlayerStats[$player['legacyPlayerId']] ?? [
                    'wins' => 0,
                    'losses' => 0,
                    'draws' => 0,
                    'games' => 0,
                    'hostGames' => 0,
                    'hostWins' => 0,
                ];
                $games = $stats['games'];
                $small = $player['small'];
                $difference = $player['difference'];
                $pointsAgainst = $small - $difference;

                $connection->insert('tournament_result', [
                    'organization_id' => (int) $organization['id'],
                    'tournament_id' => $tournamentId,
                    'player_id' => $player['playerId'],
                    'legacy_tournament_id' => $legacyTournamentId,
                    'legacy_player_id' => $player['legacyPlayerId'],
                    'place' => $player['place'],
                    'gwin' => $stats['wins'],
                    'glost' => $stats['losses'],
                    'gdraw' => $stats['draws'],
                    'games' => $games,
                    'trank' => $games > 0 ? round($player['scalps'] / $games, 3) : null,
                    'brank' => round($player['tournamentRank'], 3),
                    'points' => $games > 0 ? round($small / $games, 3) : null,
                    'pointo' => $games > 0 ? round($pointsAgainst / $games, 3) : null,
                    'hostgames' => $stats['hostGames'],
                    'hostwin' => $stats['hostWins'],
                    'masters' => null,
                ]);
            }

            $rankingCache = $resolvedPlayersByName;
            foreach ($ranking as $index => $rankingRow) {
                if (!$rankingRow['main']) {
                    continue; // todo
                }
                $context = sprintf('ranking[%d]', $index);
                $playerName = $this->requireString($rankingRow, 'player', $context);
                $rank = $this->requireFloat($rankingRow, 'rank', $context);
                $games = $this->requireInt($rankingRow, 'games', $context);
                $position = $this->requireInt($rankingRow, 'lp', $context);
                $rtype = $this->requireBool($rankingRow, 'main', $context) ? 'f' : 'w';

                $resolved = $rankingCache[$playerName] ?? null;
                if ($resolved === null) {
                    $resolvedCatalogPlayer = $this->resolvePlayer($catalog, $playerName, max(100.0, $rank));
                    if ($resolvedCatalogPlayer === null) {
                        throw new LogicException('Ranking player does not exist in db: ' . $playerName);
                    }

                    if ($resolvedCatalogPlayer['legacyPlayerId'] === null) {
                        $resolvedCatalogPlayer['legacyPlayerId'] = $nextLegacyPlayerId;
                        $nextLegacyPlayerId++;
                    }

                    $resolved = [
                        'playerId' => $resolvedCatalogPlayer['playerId'],
                        'legacyPlayerId' => $resolvedCatalogPlayer['legacyPlayerId'],
                        'nameShow' => $resolvedCatalogPlayer['nameShow'],
                        'nameAlph' => $resolvedCatalogPlayer['nameAlph'],
                        'tournamentRank' => max(100.0, $rank),
                    ];
                    $rankingCache[$resolvedCatalogPlayer['nameShow']] = $resolved;
                    $rankingCache[$playerName] = $resolved;
                }

                $connection->insert('ranking', [
                    'organization_id' => (int) $organization['id'],
                    'rtype' => $rtype,
                    'player_id' => $resolved['playerId'],
                    'tournament_id' => $tournamentId,
                    'legacy_player_id' => $resolved['legacyPlayerId'],
                    'legacy_tournament_id' => $legacyTournamentId,
                    'position' => $position,
                    'rank' => round($rank, 2),
                    'games' => $games,
                ]);
            }

            $connection->insert("text_resource", [
                "organization_id" => (int) $organization["id"],
                "resource_type" => self::AUDIT_RESOURCE_TYPE,
                "legacy_id" => $legacyTournamentId,
                "data" => json_encode([
                    "tournamentDbId" => $tournamentId,
                    "createdPlayerIds" => array_values(array_unique($createdPlayerIds)),
                    "importedAt" => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                ], JSON_THROW_ON_ERROR),
            ]);

            return $legacyTournamentId;
        });
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param array<int, array<string, mixed>> $playersByStartingPosition
     * @return array<int, array{wins:int,losses:int,draws:int,games:int,hostGames:int,hostWins:int}>
     */
    private function buildPerPlayerStats(array $results, array $playersByStartingPosition): array
    {
        $stats = [];

        foreach ($playersByStartingPosition as $player) {
            $stats[$player['legacyPlayerId']] = [
                'wins' => 0,
                'losses' => 0,
                'draws' => 0,
                'games' => 0,
                'hostGames' => 0,
                'hostWins' => 0,
            ];
        }

        foreach ($results as $index => $resultRow) {
            $context = sprintf('results[%d]', $index);
            $hostPosition = $this->requireInt($resultRow, 'host', $context);
            $guestPosition = $this->requireInt($resultRow, 'guest', $context);
            $score1 = $this->requireInt($resultRow, 'score1', $context);
            $score2 = $this->requireInt($resultRow, 'score2', $context);

            $host = $playersByStartingPosition[$hostPosition] ?? null;
            $guest = $playersByStartingPosition[$guestPosition] ?? null;
            if (($host === null || $guest === null) && !$this->isByeGame($score1, $score2)) {
                throw new BadRequestHttpException(sprintf('Could not resolve game participants for %s.', $context));
            }

            $stats[$host['legacyPlayerId']]['games']++;
            $stats[$host['legacyPlayerId']]['hostGames']++;

            if ($guest) {
                $stats[$guest['legacyPlayerId']]['games']++;
            }

            if ($score1 > $score2) {
                $stats[$host['legacyPlayerId']]['wins']++;
                $stats[$host['legacyPlayerId']]['hostWins']++;
                if ($guest) {
                    $stats[$guest['legacyPlayerId']]['losses']++;
                }
            } elseif ($score1 < $score2) {
                $stats[$host['legacyPlayerId']]['losses']++;
                $stats[$guest['legacyPlayerId']]['wins']++;
            } else {
                $stats[$host['legacyPlayerId']]['draws']++;
                $stats[$guest['legacyPlayerId']]['draws']++;
            }
        }

        return $stats;
    }

    /**
     * @param array<int, array<string, mixed>> $playersByStartingPosition
     * @return array<string, mixed>
     */
    private function findWinner(array $playersByStartingPosition): array
    {
        foreach ($playersByStartingPosition as $player) {
            if ($player['place'] === 1) {
                return $player;
            }
        }

        throw new BadRequestHttpException('Could not find tournament winner in players payload.');
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function maxRound(array $results): int
    {
        $maxRound = 0;
        foreach ($results as $index => $resultRow) {
            $maxRound = max($maxRound, $this->requireInt($resultRow, 'round', sprintf('results[%d]', $index)));
        }

        return $maxRound;
    }

    /**
     * @param array<int, array<string, mixed>> $playersByStartingPosition
     */
    private function averageTournamentRank(array $playersByStartingPosition): float
    {
        $sum = 0.0;
        foreach ($playersByStartingPosition as $player) {
            $sum += $player['tournamentRank'];
        }

        return round($sum / max(1, count($playersByStartingPosition)), 3);
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @return array<string, mixed>|null
     */
    private function resolvePlayer(array $catalog, string $playerName, float $rankHint): ?array
    {
        if ($playerName === 'Kazimierz Merklejn') {
            $playerName = 'Kazimierz.J Merklejn';
        }

        if ($playerName === 'Anna Demczyszyn') {
            $playerName = 'Anna Kowalska-Demczyszyn';
        }

        if ($playerName === 'Ala Białobrzewska') {
            $playerName = 'Alina Białobrzewska';
        }

        if ($playerName === 'Teresa Radziewicz-Choińska') {
            $playerName = 'Teresa Radziewicz';
        }

        if ($playerName === 'Bernadeta Kudlińska') {
            $playerName = 'Bernadetta Kudlińska';
        }

        if ($playerName === 'Maciej Labe') {
            $playerName = 'dane ukryte.3';
        }

        $exactMatches = array_values(array_filter(
            $catalog,
            static fn (array $candidate): bool => $candidate['nameShow'] === $playerName,
        ));
        if ($exactMatches !== []) {
            if (count($exactMatches) > 1) {
                throw new LogicException('More than one player matches for ' . $playerName);
            }
            return $exactMatches[0];
        }

        $normalizedName = $this->nameNormalizer->normalizeForMatch($playerName);
        $normalizedMatches = array_values(array_filter(
            $catalog,
            fn (array $candidate): bool => $candidate['normalized'] === $normalizedName,
        ));
        if ($normalizedMatches === []) {
            return null;
        }

        if (count($normalizedMatches) === 1) {
            return $normalizedMatches[0];
        }

        throw new LogicException('More than one player matches for ' . $playerName);
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     */
    private function createPlayer(Connection $connection, int $organizationId, string $nameShow, int $legacyPlayerId): array
    {
        $nameShow = $this->trimToLength($nameShow, 40);
        $nameAlph = $this->trimToLength($this->nameNormalizer->toAlphabeticalName($nameShow), 40);
        $playerId = (int) $connection->fetchOne(
            'INSERT INTO player (name_show, name_alph)
             VALUES (:nameShow, :nameAlph)
             RETURNING id',
            [
                'nameShow' => $nameShow,
                'nameAlph' => $nameAlph,
            ],
        );

        $connection->executeStatement(
            'INSERT INTO player_organization (player_id, organization_id)
             VALUES (:playerId, :organizationId)
             ON CONFLICT DO NOTHING',
            [
                'playerId' => $playerId,
                'organizationId' => $organizationId,
            ],
        );

        return [
            'playerId' => $playerId,
            'legacyPlayerId' => $legacyPlayerId,
            'nameShow' => $nameShow,
            'nameAlph' => $nameAlph,
            'normalized' => $this->nameNormalizer->normalizeForMatch($nameShow),
            'latestRank' => 100.0,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     * @throws Exception
     */
    private function loadPlayerCatalog(int $organizationId, int $legacyTournamentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH org_players AS (
                SELECT p.id AS player_id, p.name_show, p.name_alph
                FROM player p
                INNER JOIN player_organization po ON po.player_id = p.id
                WHERE po.organization_id = :organizationId
            ),
            player_map AS (
                SELECT DISTINCT player_id, legacy_player_id
                FROM ranking
                WHERE organization_id = :organizationId
                  AND player_id IS NOT NULL
                  AND legacy_player_id IS NOT NULL
                UNION
                SELECT DISTINCT player_id, legacy_player_id
                FROM tournament_result
                WHERE organization_id = :organizationId
                  AND player_id IS NOT NULL
                  AND legacy_player_id IS NOT NULL
                UNION
                SELECT DISTINCT player_id, legacy_player_id
                FROM play_summary
                WHERE organization_id = :organizationId
                  AND player_id IS NOT NULL
                  AND legacy_player_id IS NOT NULL
                UNION
                SELECT DISTINCT player1_id AS player_id, legacy_player1_id AS legacy_player_id
                FROM tournament_game
                WHERE organization_id = :organizationId
                  AND player1_id IS NOT NULL
                  AND legacy_player1_id IS NOT NULL
                UNION
                SELECT DISTINCT player2_id AS player_id, legacy_player2_id AS legacy_player_id
                FROM tournament_game
                WHERE organization_id = :organizationId
                  AND player2_id IS NOT NULL
                  AND legacy_player2_id IS NOT NULL
            ),
            latest_ranking AS (
                SELECT r.player_id, r.rank
                FROM ranking r
                INNER JOIN (
                    SELECT player_id, MAX(legacy_tournament_id) AS last_tournament_id
                    FROM ranking
                    WHERE organization_id = :organizationId
                      AND rtype = 'f'
                      AND player_id IS NOT NULL
                      AND legacy_tournament_id < :legacyTournamentId
                    GROUP BY player_id
                ) latest_ids
                    ON latest_ids.player_id = r.player_id
                   AND latest_ids.last_tournament_id = r.legacy_tournament_id
                WHERE r.organization_id = :organizationId
                  AND r.rtype = 'f'
            ),
            latest_result AS (
                SELECT tr.player_id, tr.brank
                FROM tournament_result tr
                INNER JOIN (
                    SELECT player_id, MAX(legacy_tournament_id) AS last_tournament_id
                    FROM tournament_result
                    WHERE organization_id = :organizationId
                      AND player_id IS NOT NULL
                      AND legacy_tournament_id < :legacyTournamentId
                    GROUP BY player_id
                ) latest_ids
                    ON latest_ids.player_id = tr.player_id
                   AND latest_ids.last_tournament_id = tr.legacy_tournament_id
                WHERE tr.organization_id = :organizationId
            )
            SELECT
                op.player_id,
                MIN(pm.legacy_player_id) AS legacy_player_id,
                op.name_show,
                op.name_alph,
                COALESCE(lr.rank, ltr.brank, 100.0) AS latest_rank
            FROM org_players op
            LEFT JOIN player_map pm ON pm.player_id = op.player_id
            LEFT JOIN latest_ranking lr ON lr.player_id = op.player_id
            LEFT JOIN latest_result ltr ON ltr.player_id = op.player_id
            GROUP BY op.player_id, op.name_show, op.name_alph, lr.rank, ltr.brank
            ORDER BY op.player_id ASC",
            [
                'organizationId' => $organizationId,
                'legacyTournamentId' => $legacyTournamentId,
            ],
        );

        return array_map(function (array $row): array {
            return [
                'playerId' => (int) $row['player_id'],
                'legacyPlayerId' => $row['legacy_player_id'] !== null ? (int) $row['legacy_player_id'] : null,
                'nameShow' => (string) $row['name_show'],
                'nameAlph' => (string) $row['name_alph'],
                'normalized' => $this->nameNormalizer->normalizeForMatch((string) $row['name_show']),
                'latestRank' => $row['latest_rank'] !== null ? (float) $row['latest_rank'] : 100.0,
            ];
        }, $rows);
    }

    /**
     * @throws Exception
     */
    private function allocateNextLegacyPlayerId(int $organizationId): int
    {
        return (int) $this->connection->fetchOne(
            'WITH legacy_ids AS (
                SELECT legacy_player_id FROM ranking WHERE organization_id = :organizationId AND legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player_id FROM tournament_result WHERE organization_id = :organizationId AND legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player_id FROM play_summary WHERE organization_id = :organizationId AND legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player1_id AS legacy_player_id FROM tournament_game WHERE organization_id = :organizationId AND legacy_player1_id IS NOT NULL
                UNION ALL
                SELECT legacy_player2_id AS legacy_player_id FROM tournament_game WHERE organization_id = :organizationId AND legacy_player2_id IS NOT NULL
            )
            SELECT COALESCE(MAX(legacy_player_id), 0) + 1
            FROM legacy_ids',
            ['organizationId' => $organizationId],
        );
    }

    /**
     * @throws Exception
     */
    private function allocateTournamentLegacyId(int $organizationId, int $dateCode): int
    {
        $suffix = (int) $this->connection->fetchOne(
            'SELECT COALESCE(MAX(legacy_id % 10), -1) + 1
             FROM tournament
             WHERE organization_id = :organizationId
               AND legacy_id BETWEEN :fromId AND :toId',
            [
                'organizationId' => $organizationId,
                'fromId' => $dateCode * 10,
                'toId' => ($dateCode * 10) + 9,
            ],
        );

        return ($dateCode * 10) + $suffix;
    }

    /**
     * @return array{id:int,code:string}|null
     * @throws Exception
     */
    private function fetchOrganization(string $organizationCode): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, code
             FROM organization
             WHERE UPPER(code) = :organizationCode
                OR UPPER(name) = :organizationCode
             ORDER BY id ASC
             LIMIT 1',
            ['organizationCode' => $organizationCode],
        );

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
        ];
    }

    /**
     * @throws Exception
     */
    private function findExistingTournamentId(int $organizationId, int $dateCode, string $fullname, string $city): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT legacy_id
             FROM tournament
             WHERE organization_id = :organizationId
               AND dt = :dateCode
               AND LOWER(COALESCE(fullname, name, '')) = LOWER(:fullname)
               AND LOWER(COALESCE(place, '')) = LOWER(:city)
             LIMIT 1",
            [
                'organizationId' => $organizationId,
                'dateCode' => $dateCode,
                'fullname' => $fullname,
                'city' => $city,
            ],
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    private function buildPlayerName(string $firstName, string $lastName): string
    {
        $fullName = trim($firstName . ' ' . $lastName);
        if ($fullName === '') {
            throw new BadRequestHttpException('Player firstName/lastName must not both be empty.');
        }

        return $fullName;
    }

    private function buildShortTournamentName(DateTimeImmutable $startDate, string $city): string
    {
        return $this->trimToLength(sprintf('%s %s', $startDate->format('ymd'), $city), 40);
    }

    private function normalizeOrganizationCode(string $value): string
    {
        return mb_strtoupper(trim($value));
    }

    private function trimToLength(string $value, int $limit): string
    {
        $value = trim($value);

        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requireString(array $row, string $field, string $context): string
    {
        $value = $row[$field] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new BadRequestHttpException(sprintf('Field "%s.%s" must be a non-empty string.', $context, $field));
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requireInt(array $row, string $field, string $context): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
            throw new BadRequestHttpException(sprintf('Field "%s.%s" must be an integer.', $context, $field));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requireFloat(array $row, string $field, string $context): float
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new BadRequestHttpException(sprintf('Field "%s.%s" must be numeric.', $context, $field));
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requireBool(array $row, string $field, string $context): bool
    {
        $value = $row[$field] ?? null;
        if (!is_bool($value)) {
            throw new BadRequestHttpException(sprintf('Field "%s.%s" must be a boolean.', $context, $field));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireArray(mixed $value, string $context): array
    {
        if (!is_array($value)) {
            throw new BadRequestHttpException(sprintf('Field "%s" must be an object.', $context));
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requireList(mixed $value, string $context): array
    {
        if (!is_array($value)) {
            throw new BadRequestHttpException(sprintf('Field "%s" must be an array.', $context));
        }

        foreach (array_values($value) as $index => $item) {
            if (!is_array($item)) {
                throw new BadRequestHttpException(sprintf('Field "%s[%d]" must be an object.', $context, $index));
            }
        }

        return array_values($value);
    }

    private function parseDate(string $value, string $context): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException(sprintf('Field "%s" must use YYYY-MM-DD format.', $context));
        }

        return $date;
    }

    private function isByeGame(int $score1, int $score2): bool
    {
        return $score1 === 300 && $score2 === 0;
    }
}
