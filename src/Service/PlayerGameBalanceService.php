<?php

namespace App\Service;

use App\ApiResource\PlayerGameBalance\PlayerGameBalance;
use App\ApiResource\PlayerGameBalance\PlayerGameBalanceRow;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerGameBalanceService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    public function getGameBalance(int $playerId): PlayerGameBalance
    {
        $playerExists = $this->connection->fetchOne(
            'SELECT 1 FROM PFSPLAYER WHERE id = :playerId',
            ['playerId' => $playerId]
        );

        if ($playerExists === false) {
            throw new NotFoundHttpException(sprintf('Player with id %d was not found.', $playerId));
        }

        $games = $this->connection->fetchAllAssociative(
            "SELECT
                CASE WHEN h.player1 = :playerId THEN h.player2 ELSE h.player1 END AS opponentId,
                p.name_show AS opponentName,
                CASE WHEN h.player1 = :playerId THEN h.result1 ELSE h.result2 END AS ownPoints,
                CASE WHEN h.player1 = :playerId THEN h.result2 ELSE h.result1 END AS opponentPoints,
                t.dt AS tournamentDate,
                h.turniej AS tournamentId,
                h.runda AS roundNo
            FROM PFSTOURHH h
            INNER JOIN PFSTOURS t ON t.id = h.turniej
            INNER JOIN PFSPLAYER p ON p.id = CASE WHEN h.player1 = :playerId THEN h.player2 ELSE h.player1 END
            WHERE h.player1 = :playerId OR h.player2 = :playerId
            ORDER BY t.dt ASC, h.turniej ASC, h.runda ASC",
            ['playerId' => $playerId]
        );

        $byOpponent = [];
        foreach ($games as $game) {
            $opponentId = (int) $game['opponentId'];
            if (!isset($byOpponent[$opponentId])) {
                $byOpponent[$opponentId] = [
                    'opponentId' => $opponentId,
                    'opponent' => (string) $game['opponentName'],
                    'wins' => 0,
                    'draws' => 0,
                    'losses' => 0,
                    'pointsFor' => 0,
                    'pointsAgainst' => 0,
                    'games' => [],
                ];
            }

            $ownPoints = (int) $game['ownPoints'];
            $opponentPoints = (int) $game['opponentPoints'];

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
                'tournamentDate' => (int) $game['tournamentDate'],
                'tournamentId' => (int) $game['tournamentId'],
                'roundNo' => (int) $game['roundNo'],
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
