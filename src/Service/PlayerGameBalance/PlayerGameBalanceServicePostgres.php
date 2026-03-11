<?php

namespace App\Service\PlayerGameBalance;

use App\ApiResource\PlayerGameBalance\PlayerGameBalance;
use App\ApiResource\PlayerGameBalance\PlayerGameBalanceRow;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerGameBalanceServicePostgres implements PlayerGameBalanceServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function getGameBalance(int $playerId): PlayerGameBalance
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

        $games = $this->connection->fetchAllAssociative(
            "SELECT
                CASE WHEN h.legacy_player1_id = :playerId THEN h.legacy_player2_id ELSE h.legacy_player1_id END AS opponent_id,
                p.name_show AS opponent_name,
                CASE WHEN h.legacy_player1_id = :playerId THEN h.result1 ELSE h.result2 END AS own_points,
                CASE WHEN h.legacy_player1_id = :playerId THEN h.result2 ELSE h.result1 END AS opponent_points,
                t.dt AS tournament_date,
                h.legacy_tournament_id AS tournament_id,
                h.round_no AS round_no
            FROM tournament_game h
            INNER JOIN tournament t
                ON t.organization_id = h.organization_id
               AND t.legacy_id = h.legacy_tournament_id
            INNER JOIN player p
                ON p.id = CASE WHEN h.legacy_player1_id = :playerId THEN h.player2_id ELSE h.player1_id END
            WHERE h.organization_id = :organizationId
              AND (h.legacy_player1_id = :playerId OR h.legacy_player2_id = :playerId)
              AND h.legacy_tournament_id IS NOT NULL
              AND h.legacy_player1_id IS NOT NULL
              AND h.legacy_player2_id IS NOT NULL
            ORDER BY t.dt ASC, h.legacy_tournament_id ASC, h.round_no ASC",
            [
                'organizationId' => $organizationId,
                'playerId' => $playerId,
            ]
        );

        $byOpponent = [];
        foreach ($games as $game) {
            $opponentId = (int) $game['opponent_id'];
            if (!isset($byOpponent[$opponentId])) {
                $byOpponent[$opponentId] = [
                    'opponentId' => $opponentId,
                    'opponent' => (string) $game['opponent_name'],
                    'wins' => 0,
                    'draws' => 0,
                    'losses' => 0,
                    'pointsFor' => 0,
                    'pointsAgainst' => 0,
                    'games' => [],
                ];
            }

            $ownPoints = (int) $game['own_points'];
            $opponentPoints = (int) $game['opponent_points'];

            if ($ownPoints > $opponentPoints) {
                $byOpponent[$opponentId]['wins']++;
            } elseif ($ownPoints < $opponentPoints) {
                $byOpponent[$opponentId]['losses']++;
            } else {
                $byOpponent[$opponentId]['draws']++;
            }

            $byOpponent[$opponentId]['pointsFor'] += $ownPoints;
            $byOpponent[$opponentId]['pointsAgainst'] += $opponentPoints;
            $byOpponent[$opponentId]['games'][] = [
                'ownPoints' => $ownPoints,
                'opponentPoints' => $opponentPoints,
                'tournamentDate' => (int) $game['tournament_date'],
                'tournamentId' => (int) $game['tournament_id'],
                'roundNo' => (int) $game['round_no'],
            ];
        }

        $rows = [];
        foreach ($byOpponent as $entry) {
            $totalGames = $entry['wins'] + $entry['draws'] + $entry['losses'];
            if ($totalGames <= 0) {
                continue;
            }

            $rows[] = [
                'opponentId' => $entry['opponentId'],
                'opponent' => $entry['opponent'],
                'winPercent' => round(($entry['wins'] * 100) / $totalGames, 2),
                'gameBalance' => $entry['wins'] - $entry['losses'],
                'smallPointsBalance' => $entry['pointsFor'] - $entry['pointsAgainst'],
                'wins' => $entry['wins'],
                'draws' => $entry['draws'],
                'losses' => $entry['losses'],
                'streak' => $this->calculateStreak($entry['games']),
                'averagePoints' => round($entry['pointsFor'] / $totalGames, 2),
                'averageOpponentPoints' => round($entry['pointsAgainst'] / $totalGames, 2),
                'totalGames' => $totalGames,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $byWinPercent = $b['winPercent'] <=> $a['winPercent'];
            if ($byWinPercent !== 0) {
                return $byWinPercent;
            }

            $byGames = $b['totalGames'] <=> $a['totalGames'];
            if ($byGames !== 0) {
                return $byGames;
            }

            return strcmp($a['opponent'], $b['opponent']);
        });

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new PlayerGameBalanceRow(
                position: $index + 1,
                opponentId: $row['opponentId'],
                opponent: $row['opponent'],
                winPercent: $row['winPercent'],
                gameBalance: $row['gameBalance'],
                smallPointsBalance: $row['smallPointsBalance'],
                wins: $row['wins'],
                draws: $row['draws'],
                losses: $row['losses'],
                streak: $row['streak'],
                averagePoints: $row['averagePoints'],
                averageOpponentPoints: $row['averageOpponentPoints'],
                totalGames: $row['totalGames'],
            );
        }

        return new PlayerGameBalance($resultRows);
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
     * @param array<int, array{ownPoints:int, opponentPoints:int, tournamentDate:int, tournamentId:int, roundNo:int}> $games
     */
    private function calculateStreak(array $games): string
    {
        usort($games, static function (array $a, array $b): int {
            return [$b['tournamentDate'], $b['tournamentId'], $b['roundNo']] <=> [$a['tournamentDate'], $a['tournamentId'], $a['roundNo']];
        });

        if ($games === []) {
            return '0';
        }

        $first = $games[0];
        if ($first['ownPoints'] === $first['opponentPoints']) {
            return '0';
        }

        $isWinStreak = $first['ownPoints'] > $first['opponentPoints'];
        $count = 0;

        foreach ($games as $game) {
            if ($game['ownPoints'] === $game['opponentPoints']) {
                break;
            }

            $isWin = $game['ownPoints'] > $game['opponentPoints'];
            if ($isWin !== $isWinStreak) {
                break;
            }

            $count++;
        }

        return sprintf('%s%d', $isWinStreak ? '+' : '-', $count);
    }
}
