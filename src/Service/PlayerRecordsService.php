<?php

namespace App\Service;

use App\ApiResource\PlayerRecords\PlayerRecordsRow;
use App\ApiResource\PlayerRecords\PlayerRecordsTable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerRecordsService
{
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
        $playerExists = $this->connection->fetchOne(
            'SELECT 1 FROM PFSPLAYER WHERE id = :playerId',
            ['playerId' => $playerId]
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
        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                h.turniej AS tournamentId,
                t.dt AS tournamentDate,
                COALESCE(t.fullname, t.name) AS tournamentName,
                h.runda AS round,
                h.result1 AS ownPoints,
                h.result2 AS opponentPoints,
                p.id AS opponentId,
                p.name_show AS opponentName
            FROM PFSTOURHH h
            INNER JOIN PFSTOURS t ON t.id = h.turniej
            INNER JOIN PFSPLAYER p ON p.id = h.player2
            WHERE h.player1 = :playerId
            ORDER BY t.dt ASC, h.turniej ASC, h.runda ASC, h.player2 ASC",
            ['playerId' => $playerId]
        );

        return array_map(
            static fn (array $row): array => [
                'ownPoints' => (int) $row['ownPoints'],
                'opponentPoints' => (int) $row['opponentPoints'],
                'opponentName' => (string) $row['opponentName'],
                'opponentId' => (int) $row['opponentId'],
                'tournamentName' => (string) $row['tournamentName'],
                'tournamentDate' => (int) $row['tournamentDate'],
                'tournamentId' => (int) $row['tournamentId'],
                'round' => (int) $row['round'],
            ],
            $rows
        );
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
