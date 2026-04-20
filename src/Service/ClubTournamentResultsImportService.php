<?php

declare(strict_types=1);

namespace App\Service;

use App\ClubTournamentImport\ClubTournamentImportResult;
use App\ClubTournamentImport\ParsedClubGame;
use App\ClubTournamentImport\ParsedClubPlayer;
use App\ClubTournamentImport\ParsedClubTournamentResults;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class ClubTournamentResultsImportService
{
    public const int AUDIT_RESOURCE_TYPE = 9002;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private PfsNameNormalizer $nameNormalizer,
        private ClubTournamentStandingsBuilder $standingsBuilder,
    ) {
    }

    /**
     * @throws Exception
     */
    public function import(ParsedClubTournamentResults $results, int $organizationId): ClubTournamentImportResult
    {
        if ($organizationId <= 0) {
            throw new BadRequestHttpException('Organization id must be a positive integer.');
        }

        if ($results->players === []) {
            throw new BadRequestHttpException('Parsed tournament has no players.');
        }

        $organization = $this->fetchOrganization($organizationId);
        if ($organization === null) {
            throw new BadRequestHttpException(sprintf('Organization id %d was not found.', $organizationId));
        }

        $dateCode = $results->getDateCode();
        $fullname = $this->trimToLength($results->name, 80);
        $existingTournamentId = $this->findExistingTournamentId($organizationId, $dateCode, $fullname);
        if ($existingTournamentId !== null) {
            throw new ConflictHttpException(sprintf('Tournament already exists with id %d.', $existingTournamentId));
        }

        return $this->connection->transactional(function (Connection $connection) use ($results, $organizationId, $dateCode, $fullname): ClubTournamentImportResult {
            $legacyTournamentId = $this->allocateTournamentLegacyId($organizationId, $dateCode);
            $catalog = $this->loadPlayerCatalog($organizationId, $legacyTournamentId);
            $nextLegacyPlayerId = $this->allocateNextLegacyPlayerId($organizationId);

            $createdPlayerIds = [];
            $linkedPlayerIds = [];
            $playersByPosition = [];

            foreach ($results->players as $player) {
                if (isset($playersByPosition[$player->position])) {
                    throw new BadRequestHttpException(sprintf('Duplicate player position %d.', $player->position));
                }

                $resolved = $this->resolvePlayer($catalog, $player->name);
                if ($resolved === null) {
                    $resolved = $this->createPlayer($connection, $organizationId, $player->name, $player->city, $nextLegacyPlayerId);
                    $catalog[] = $resolved;
                    $createdPlayerIds[] = $resolved['playerId'];
                    $nextLegacyPlayerId++;
                } else {
                    if (!$resolved['isInOrganization']) {
                        $this->linkPlayerToOrganization($connection, $resolved['playerId'], $organizationId);
                        $resolved['isInOrganization'] = true;
                        $linkedPlayerIds[] = $resolved['playerId'];
                    }

                    if ($resolved['legacyPlayerId'] === null) {
                        $resolved['legacyPlayerId'] = $nextLegacyPlayerId;
                        $nextLegacyPlayerId++;
                    }
                }

                $playersByPosition[$player->position] = [
                    'source' => $player,
                    'playerId' => $resolved['playerId'],
                    'legacyPlayerId' => $resolved['legacyPlayerId'],
                    'nameShow' => $resolved['nameShow'],
                    'nameAlph' => $resolved['nameAlph'],
                ];
            }

            $standings = $this->standingsBuilder->buildStandings($playersByPosition);
            $winner = $standings[0];
            $rounds = $this->maxRound($results->players);

            $tournamentId = (int) $connection->fetchOne(
                'INSERT INTO tournament (
                    organization_id, legacy_id, dt, name, fullname, winner_player_id, legacy_winner_player_id,
                    trank, players_count, rounds, rrecreated, team, mcategory, wksum, series_id, legacy_series_id,
                    start_round, referee, place, organizer, urlid
                ) VALUES (
                    :organizationId, :legacyId, :dt, :name, :fullname, :winnerPlayerId, :legacyWinnerPlayerId,
                    :trank, :playersCount, :rounds, :rrecreated, :team, :mcategory, :wksum, :seriesId, :legacySeriesId,
                    :startRound, :referee, :place, :organizer, :urlid
                ) RETURNING id',
                [
                    'organizationId' => $organizationId,
                    'legacyId' => $legacyTournamentId,
                    'dt' => $dateCode,
                    'name' => $this->trimToLength($results->name, 40),
                    'fullname' => $fullname,
                    'winnerPlayerId' => $winner['playerId'],
                    'legacyWinnerPlayerId' => $winner['legacyPlayerId'],
                    'trank' => $this->averageInitialRank($results->players),
                    'playersCount' => count($results->players),
                    'rounds' => $rounds,
                    'rrecreated' => '',
                    'team' => null,
                    'mcategory' => null,
                    'wksum' => 0.0,
                    'seriesId' => null,
                    'legacySeriesId' => null,
                    'startRound' => $dateCode,
                    'referee' => null,
                    'place' => null,
                    'organizer' => null,
                    'urlid' => null,
                ],
            );

            $gamesCount = $this->insertTournamentGames($connection, $organizationId, $tournamentId, $legacyTournamentId, $playersByPosition);

            foreach ($standings as $index => $standing) {
                $games = $standing['games'];
                $connection->insert('tournament_result', [
                    'organization_id' => $organizationId,
                    'tournament_id' => $tournamentId,
                    'player_id' => $standing['playerId'],
                    'legacy_tournament_id' => $legacyTournamentId,
                    'legacy_player_id' => $standing['legacyPlayerId'],
                    'place' => $index + 1,
                    'gwin' => $standing['wins'],
                    'glost' => $standing['losses'],
                    'gdraw' => $standing['draws'],
                    'games' => $games,
                    'trank' => $standing['achievedRank'],
                    'brank' => $standing['initialRank'],
                    'points' => $games > 0 ? round($standing['pointsFor'] / $games, 3) : null,
                    'pointo' => $games > 0 ? round($standing['pointsAgainst'] / $games, 3) : null,
                    'hostgames' => 0,
                    'hostwin' => 0,
                    'masters' => null,
                ]);
            }

            $connection->insert('text_resource', [
                'organization_id' => $organizationId,
                'resource_type' => self::AUDIT_RESOURCE_TYPE,
                'legacy_id' => $legacyTournamentId,
                'data' => json_encode([
                    'tournamentDbId' => $tournamentId,
                    'createdPlayerIds' => array_values(array_unique($createdPlayerIds)),
                    'linkedPlayerIds' => array_values(array_unique($linkedPlayerIds)),
                    'importedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                ], JSON_THROW_ON_ERROR),
            ]);

            return new ClubTournamentImportResult(
                tournamentId: $tournamentId,
                legacyTournamentId: $legacyTournamentId,
                playersCount: count($results->players),
                gamesCount: $gamesCount,
                createdPlayerIds: array_values(array_unique($createdPlayerIds)),
                linkedPlayerIds: array_values(array_unique($linkedPlayerIds)),
            );
        });
    }

    /**
     * @param array<int, array{source:ParsedClubPlayer,playerId:int,legacyPlayerId:int,nameShow:string,nameAlph:string}> $playersByPosition
     * @throws Exception
     */
    private function insertTournamentGames(
        Connection $connection,
        int $organizationId,
        int $tournamentId,
        int $legacyTournamentId,
        array $playersByPosition,
    ): int {
        $seenGames = [];
        $inserted = 0;

        foreach ($playersByPosition as $position => $player) {
            foreach ($player['source']->games as $game) {
                if ($game->isBye) {
                    $this->insertGameRow(
                        $connection,
                        $organizationId,
                        $tournamentId,
                        $legacyTournamentId,
                        $game,
                        $player,
                        null,
                        350,
                        0,
                        1,
                    );
                    $inserted++;
                    continue;
                }

                $opponentPosition = $game->opponentPosition;
                if ($opponentPosition === null || !isset($playersByPosition[$opponentPosition])) {
                    throw new BadRequestHttpException(sprintf('Could not resolve opponent "%s" in round %d.', $game->opponentName, $game->round));
                }

                $key = sprintf('%d|%d|%d|%d', $game->round, $game->table ?? 0, min($position, $opponentPosition), max($position, $opponentPosition));
                if (isset($seenGames[$key])) {
                    continue;
                }
                $seenGames[$key] = true;

                $opponent = $playersByPosition[$opponentPosition];
                $player1 = $position < $opponentPosition ? $player : $opponent;
                $player2 = $position < $opponentPosition ? $opponent : $player;
                $score1 = $position < $opponentPosition ? $game->pointsFor : $game->pointsAgainst;
                $score2 = $position < $opponentPosition ? $game->pointsAgainst : $game->pointsFor;

                if ($score1 === null || $score2 === null) {
                    throw new BadRequestHttpException(sprintf('Missing score in round %d table %d.', $game->round, $game->table ?? 0));
                }

                $this->insertGameRow($connection, $organizationId, $tournamentId, $legacyTournamentId, $game, $player1, $player2, $score1, $score2, 1);
                $this->insertGameRow($connection, $organizationId, $tournamentId, $legacyTournamentId, $game, $player2, $player1, $score2, $score1, 2);
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * @param array{source:ParsedClubPlayer,playerId:int,legacyPlayerId:int,nameShow:string,nameAlph:string} $player1
     * @param array{source:ParsedClubPlayer,playerId:int,legacyPlayerId:int,nameShow:string,nameAlph:string}|null $player2
     * @throws Exception
     */
    private function insertGameRow(
        Connection     $connection,
        int            $organizationId,
        int            $tournamentId,
        int            $legacyTournamentId,
        ParsedClubGame $game,
        array          $player1,
        ?array         $player2,
        int            $score1,
        int            $score2,
        int            $host,
    ): void {
        $connection->insert('tournament_game', [
            'organization_id' => $organizationId,
            'tournament_id' => $tournamentId,
            'player1_id' => $player1['playerId'],
            'player2_id' => $player2['playerId'] ?? null,
            'legacy_tournament_id' => $legacyTournamentId,
            'round_no' => $game->round,
            'table_no' => $game->table,
            'legacy_player1_id' => $player1['legacyPlayerId'],
            'legacy_player2_id' => $player2['legacyPlayerId'] ?? null,
            'result1' => $score1,
            'result2' => $score2,
            'ranko' => $player2 !== null ? $player2['source']->initialRank : 100,
            'host' => $host,
            'gcg' => null,
            'gcg_updated_at' => null,
        ]);
    }

    /**
     * @param list<ParsedClubPlayer> $players
     */
    private function maxRound(array $players): int
    {
        $maxRound = 0;
        foreach ($players as $player) {
            foreach ($player->games as $game) {
                $maxRound = max($maxRound, $game->round);
            }
        }

        return $maxRound;
    }

    /**
     * @param list<ParsedClubPlayer> $players
     */
    private function averageInitialRank(array $players): float
    {
        $sum = 0;
        foreach ($players as $player) {
            $sum += $player->initialRank;
        }

        return round($sum / max(1, count($players)), 3);
    }

    /**
     * @return array{id:int}|null
     * @throws Exception
     */
    private function fetchOrganization(int $organizationId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id FROM organization WHERE id = :organizationId',
            ['organizationId' => $organizationId],
        );

        return $row === false ? null : ['id' => (int) $row['id']];
    }

    /**
     * @throws Exception
     */
    private function findExistingTournamentId(int $organizationId, int $dateCode, string $fullname): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT id
             FROM tournament
             WHERE organization_id = :organizationId
               AND dt = :dateCode
               AND LOWER(COALESCE(fullname, name, '')) = LOWER(:fullname)
             LIMIT 1",
            [
                'organizationId' => $organizationId,
                'dateCode' => $dateCode,
                'fullname' => $fullname,
            ],
        );

        return $value === false || $value === null ? null : (int) $value;
    }

    /**
     * @return list<array{playerId:int,legacyPlayerId:int|null,nameShow:string,nameAlph:string,normalized:string,isInOrganization:bool}>
     * @throws Exception
     */
    private function loadPlayerCatalog(int $organizationId, int $legacyTournamentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH player_map AS (
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
                SELECT DISTINCT player1_id AS player_id, legacy_player1_id AS legacy_player_id
                FROM tournament_game
                WHERE organization_id = :organizationId
                  AND player1_id IS NOT NULL
                  AND legacy_player1_id IS NOT NULL
            )
            SELECT
                p.id AS player_id,
                MIN(pm.legacy_player_id) AS legacy_player_id,
                p.name_show,
                p.name_alph,
                CASE WHEN po.player_id IS NULL THEN 0 ELSE 1 END AS is_in_organization
            FROM player p
            LEFT JOIN player_organization po
                ON po.player_id = p.id
               AND po.organization_id = :organizationId
            LEFT JOIN player_map pm ON pm.player_id = p.id
            WHERE p.name_show IS NOT NULL
            GROUP BY p.id, p.name_show, p.name_alph, po.player_id
            ORDER BY p.id ASC",
            [
                'organizationId' => $organizationId,
                'legacyTournamentId' => $legacyTournamentId,
            ],
        );

        return array_map(fn (array $row): array => [
            'playerId' => (int) $row['player_id'],
            'legacyPlayerId' => $row['legacy_player_id'] !== null ? (int) $row['legacy_player_id'] : null,
            'nameShow' => (string) $row['name_show'],
            'nameAlph' => (string) ($row['name_alph'] ?? ''),
            'normalized' => $this->nameNormalizer->normalizeForMatch((string) $row['name_show']),
            'isInOrganization' => (int) $row['is_in_organization'] === 1,
        ], $rows);
    }

    /**
     * @param list<array{playerId:int,legacyPlayerId:int|null,nameShow:string,nameAlph:string,normalized:string,isInOrganization:bool}> $catalog
     * @return array{playerId:int,legacyPlayerId:int|null,nameShow:string,nameAlph:string,normalized:string,isInOrganization:bool}|null
     */
    private function resolvePlayer(array $catalog, string $playerName): ?array
    {
        $exactMatches = array_values(array_filter(
            $catalog,
            static fn (array $candidate): bool => $candidate['nameShow'] === $playerName,
        ));
        if (count($exactMatches) === 1) {
            return $exactMatches[0];
        }
        if (count($exactMatches) > 1) {
            throw new LogicException('More than one player matches for ' . $playerName);
        }

        $normalizedName = $this->nameNormalizer->normalizeForMatch($playerName);
        $normalizedMatches = array_values(array_filter(
            $catalog,
            static fn (array $candidate): bool => $candidate['normalized'] === $normalizedName,
        ));

        if (count($normalizedMatches) === 1) {
            return $normalizedMatches[0];
        }

        if (count($normalizedMatches) > 1) {
            throw new LogicException('More than one player matches for ' . $playerName);
        }

        return null;
    }

    /**
     * @return array{playerId:int,legacyPlayerId:int,nameShow:string,nameAlph:string,normalized:string,isInOrganization:bool}
     * @throws Exception
     */
    private function createPlayer(Connection $connection, int $organizationId, string $nameShow, string $city, int $legacyPlayerId): array
    {
        $nameShow = $this->trimToLength($nameShow, 40);
        $nameAlph = $this->trimToLength($this->nameNormalizer->toAlphabeticalName($nameShow), 40);
        $playerId = (int) $connection->fetchOne(
            'INSERT INTO player (name_show, name_alph, city)
             VALUES (:nameShow, :nameAlph, :city)
             RETURNING id',
            [
                'nameShow' => $nameShow,
                'nameAlph' => $nameAlph,
                'city' => $this->trimToLength($city, 256),
            ],
        );

        $this->linkPlayerToOrganization($connection, $playerId, $organizationId);

        return [
            'playerId' => $playerId,
            'legacyPlayerId' => $legacyPlayerId,
            'nameShow' => $nameShow,
            'nameAlph' => $nameAlph,
            'normalized' => $this->nameNormalizer->normalizeForMatch($nameShow),
            'isInOrganization' => true,
        ];
    }

    /**
     * @throws Exception
     */
    private function linkPlayerToOrganization(Connection $connection, int $playerId, int $organizationId): void
    {
        $connection->executeStatement(
            'INSERT INTO player_organization (player_id, organization_id)
             VALUES (:playerId, :organizationId)
             ON CONFLICT DO NOTHING',
            [
                'playerId' => $playerId,
                'organizationId' => $organizationId,
            ],
        );
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

    private function trimToLength(string $value, int $limit): string
    {
        $value = trim($value);

        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit);
    }
}
