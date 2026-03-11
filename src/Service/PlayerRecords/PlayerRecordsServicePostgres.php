<?php

namespace App\Service;

use App\ApiResource\PlayerRecords\PlayerRecordsRow;
use App\ApiResource\PlayerRecords\PlayerRecordsTable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerRecordsServicePostgres implements PlayerRecordsServiceInterface {
    private const string ORGANIZATION_CODE = 'PFS';
    private const array TOURNAMENT_NAME_OVERRIDES = [
        199703150 => '',
        199811220 => '',
        200506191 => '',
        200606180 => '',
        200706100 => '',
        200805250 => '',
        201204220 => "XVI Mistrzostwa Ziemi Kujawskiej w Scrabble 'O Kryształowe Jajo Świąteczne' pod ",
    ];
    private const GAME_RECORD_TYPES = [
        'most-points',
        'least-points',
        'points-highest-sum',
        'points-lowest-sum',
        'opponent-most-points',
        'opponent-least-points',
        'highest-win',
        'highest-lose',
        'highest-draw',
        'lost-with-most-points',
        'won-with-least-points',
        'won-with-most-points-by-opponent',
        'lost-with-least-points-by-opponent',
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function getRecords(int $playerId, string $recordType, int $limit = 10, ?int $min = null): PlayerRecordsTable
    {
        $this->assertPlayerExists($playerId);
        $limit = max(1, min(100, $limit));
        $games = $this->fetchPlayerGames($playerId);

        if (in_array($recordType, self::GAME_RECORD_TYPES, true)) {
            return new PlayerRecordsTable($recordType, $this->buildGameRows($games, $recordType, $limit));
        }

        return new PlayerRecordsTable($recordType, match ($recordType) {
            'win-streak' => $this->buildStreakRows(
                $games,
                static fn (array $game): bool => $game['ownPoints'] > $game['opponentPoints'],
                $limit
            ),
            'lose-streak' => $this->buildStreakRows(
                $games,
                static fn (array $game): bool => $game['ownPoints'] < $game['opponentPoints'],
                $limit
            ),
            'streak-by-points' => $this->buildStreakRows(
                $games,
                static fn (array $game): bool => $game['ownPoints'] >= max(0, (int) $min),
                $limit
            ),
            'streak-by-sum' => $this->buildStreakRows(
                $games,
                static fn (array $game): bool => ($game['ownPoints'] + $game['opponentPoints']) >= max(0, (int) $min),
                $limit
            ),
            'win-streak-by-player' => $this->buildStreakByPlayerRows(
                $games,
                static fn (array $game): bool => $game['ownPoints'] > $game['opponentPoints'],
                $limit
            ),
            'lose-streak-by-player' => $this->buildStreakByPlayerRows(
                $games,
                static fn (array $game): bool => $game['ownPoints'] < $game['opponentPoints'],
                $limit
            ),
            default => [],
        });
    }

    private function assertPlayerExists(int $playerId): void
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            throw new NotFoundHttpException(sprintf('Player with id %d was not found.', $playerId));
        }

        $playerExists = $this->connection->fetchOne(
            'SELECT 1
             FROM (
                SELECT legacy_player_id AS legacy_id
                FROM ranking
                WHERE organization_id = :organizationId
                  AND legacy_player_id = :playerId
                UNION ALL
                SELECT legacy_player_id AS legacy_id
                FROM tournament_result
                WHERE organization_id = :organizationId
                  AND legacy_player_id = :playerId
                UNION ALL
                SELECT legacy_player1_id AS legacy_id
                FROM tournament_game
                WHERE organization_id = :organizationId
                  AND legacy_player1_id = :playerId
                UNION ALL
                SELECT legacy_player2_id AS legacy_id
                FROM tournament_game
                WHERE organization_id = :organizationId
                  AND legacy_player2_id = :playerId
             ) x
             LIMIT 1',
            [
                'organizationId' => $organizationId,
                'playerId' => $playerId,
            ]
        );

        if ($playerExists === false) {
            throw new NotFoundHttpException(sprintf('Player with id %d was not found.', $playerId));
        }
    }

    /**
     * @return array<int, array{
     *   ownPoints: int,
     *   opponentPoints: int,
     *   opponentName: string,
     *   opponentId: int,
     *   tournamentName: string,
     *   tournamentDate: int,
     *   tournamentId: int,
     *   round: int
     * }>
     */
    private function fetchPlayerGames(int $playerId): array
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                h.legacy_tournament_id AS tournament_id,
                t.dt AS tournament_date,
                COALESCE(t.fullname, t.name) AS tournament_name,
                h.round_no AS round_no,
                h.result1 AS own_points,
                h.result2 AS opponent_points,
                h.legacy_player2_id AS opponent_id,
                p.name_show AS opponent_name
            FROM tournament_game h
            INNER JOIN tournament t
                ON t.organization_id = h.organization_id
               AND t.legacy_id = h.legacy_tournament_id
            INNER JOIN player p ON p.id = h.player2_id
            WHERE h.organization_id = :organizationId
              AND h.legacy_player1_id = :playerId
              AND h.legacy_tournament_id IS NOT NULL
              AND h.legacy_player2_id IS NOT NULL
            ORDER BY t.dt ASC, h.legacy_tournament_id ASC, h.round_no ASC, h.legacy_player2_id ASC",
            [
                'organizationId' => $organizationId,
                'playerId' => $playerId,
            ]
        );

        return array_map(
            static fn (array $row): array => [
                'ownPoints' => (int) $row['own_points'],
                'opponentPoints' => (int) $row['opponent_points'],
                'opponentName' => (string) $row['opponent_name'],
                'opponentId' => (int) $row['opponent_id'],
                'tournamentName' => self::TOURNAMENT_NAME_OVERRIDES[(int) $row['tournament_id']] ?? (string) $row['tournament_name'],
                'tournamentDate' => (int) $row['tournament_date'],
                'tournamentId' => (int) $row['tournament_id'],
                'round' => (int) $row['round_no'],
            ],
            $rows
        );
    }

    private function fetchOrganizationId(): ?int
    {
        $value = $this->connection->fetchOne(
            'SELECT id FROM organization WHERE code = :code LIMIT 1',
            ['code' => self::ORGANIZATION_CODE]
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<int, array<string, int|string>> $games
     * @return PlayerRecordsRow[]
     */
    private function buildGameRows(array $games, string $recordType, int $limit): array
    {
        $filteredGames = array_filter($games, function (array $game) use ($recordType): bool {
            return match ($recordType) {
                'highest-win', 'won-with-least-points', 'won-with-most-points-by-opponent' => $game['ownPoints'] > $game['opponentPoints'],
                'highest-lose', 'lost-with-most-points', 'lost-with-least-points-by-opponent' => $game['ownPoints'] < $game['opponentPoints'],
                'highest-draw' => $game['ownPoints'] === $game['opponentPoints'],
                default => true,
            };
        });

        usort($filteredGames, function (array $a, array $b) use ($recordType): int {
            $metricA = $this->calculateMetric((int) $a['ownPoints'], (int) $a['opponentPoints'], $recordType);
            $metricB = $this->calculateMetric((int) $b['ownPoints'], (int) $b['opponentPoints'], $recordType);

            if ($metricA === $metricB) {
                return [(int) $b['tournamentDate'], (int) $b['round']] <=> [(int) $a['tournamentDate'], (int) $a['round']];
            }

            $ascending = in_array($recordType, [
                'least-points',
                'points-lowest-sum',
                'opponent-least-points',
                'won-with-least-points',
                'lost-with-least-points-by-opponent',
            ], true);

            return $ascending ? $metricA <=> $metricB : $metricB <=> $metricA;
        });

        $rows = [];
        foreach (array_slice($filteredGames, 0, $limit) as $index => $game) {
            $points = $this->calculateMetric((int) $game['ownPoints'], (int) $game['opponentPoints'], $recordType);
            $rows[] = new PlayerRecordsRow(
                position: $index + 1,
                points: $points,
                opponent: (string) $game['opponentName'],
                score: sprintf('%d:%d', $game['ownPoints'], $game['opponentPoints']),
                tournament: (string) $game['tournamentName'],
            );
        }

        return $rows;
    }

    private function calculateMetric(int $ownPoints, int $opponentPoints, string $recordType): int
    {
        return match ($recordType) {
            'most-points', 'least-points', 'highest-draw', 'lost-with-most-points', 'won-with-least-points' => $ownPoints,
            'points-highest-sum', 'points-lowest-sum' => $ownPoints + $opponentPoints,
            'opponent-most-points', 'opponent-least-points', 'won-with-most-points-by-opponent', 'lost-with-least-points-by-opponent' => $opponentPoints,
            'highest-win' => $ownPoints - $opponentPoints,
            'highest-lose' => $opponentPoints - $ownPoints,
            default => $ownPoints,
        };
    }

    /**
     * @param array<int, array<string, int|string>> $games
     * @param callable(array<string, int|string>): bool $condition
     * @return PlayerRecordsRow[]
     */
    private function buildStreakRows(array $games, callable $condition, int $limit): array
    {
        $streaks = [];
        $currentLength = 0;
        $currentTournaments = [];

        $pushCurrent = function () use (&$streaks, &$currentLength, &$currentTournaments): void {
            if ($currentLength <= 0) {
                return;
            }

            $streaks[] = [
                'streak' => $currentLength,
                'tournaments' => implode(', ', array_keys($currentTournaments)),
            ];

            $currentLength = 0;
            $currentTournaments = [];
        };

        foreach ($games as $game) {
            if (!$condition($game)) {
                $pushCurrent();
                continue;
            }

            $currentLength++;
            $currentTournaments[(string) $game['tournamentName']] = true;
        }
        $pushCurrent();

        usort($streaks, static fn (array $a, array $b): int => $b['streak'] <=> $a['streak']);

        $rows = [];
        foreach (array_slice($streaks, 0, $limit) as $index => $streak) {
            $rows[] = new PlayerRecordsRow(
                position: $index + 1,
                streak: (int) $streak['streak'],
                tournaments: (string) $streak['tournaments'],
            );
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, int|string>> $games
     * @param callable(array<string, int|string>): bool $condition
     * @return PlayerRecordsRow[]
     */
    private function buildStreakByPlayerRows(array $games, callable $condition, int $limit): array
    {
        $gamesByOpponent = [];
        foreach ($games as $game) {
            $gamesByOpponent[(int) $game['opponentId']][] = $game;
        }

        $streaks = [];
        foreach ($gamesByOpponent as $opponentGames) {
            $currentLength = 0;
            $firstTournament = null;
            $lastTournament = null;

            foreach ($opponentGames as $game) {
                if (!$condition($game)) {
                    if ($currentLength > 0) {
                        $streaks[] = [
                            'streak' => $currentLength,
                            'opponent' => (string) $game['opponentName'],
                            'firstTournament' => (string) $firstTournament,
                            'lastTournament' => (string) $lastTournament,
                        ];
                    }

                    $currentLength = 0;
                    $firstTournament = null;
                    $lastTournament = null;
                    continue;
                }

                if ($currentLength === 0) {
                    $firstTournament = $game['tournamentName'];
                }

                $currentLength++;
                $lastTournament = $game['tournamentName'];
            }

            if ($currentLength > 0) {
                $streaks[] = [
                    'streak' => $currentLength,
                    'opponent' => (string) $opponentGames[0]['opponentName'],
                    'firstTournament' => (string) $firstTournament,
                    'lastTournament' => (string) $lastTournament,
                ];
            }
        }

        usort($streaks, static function (array $a, array $b): int {
            $byStreak = $b['streak'] <=> $a['streak'];
            if ($byStreak !== 0) {
                return $byStreak;
            }

            return strcmp((string) $a['opponent'], (string) $b['opponent']);
        });

        $rows = [];
        foreach (array_slice($streaks, 0, $limit) as $index => $streak) {
            $rows[] = new PlayerRecordsRow(
                position: $index + 1,
                streak: (int) $streak['streak'],
                opponent: (string) $streak['opponent'],
                firstTournament: (string) $streak['firstTournament'],
                lastTournament: (string) $streak['lastTournament'],
            );
        }

        return $rows;
    }
}
