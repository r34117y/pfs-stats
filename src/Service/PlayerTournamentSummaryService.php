<?php

namespace App\Service;

use App\ApiResource\PlayerTournamentSummary\PlayerTournamentSummary;
use App\ApiResource\PlayerTournamentSummary\PlayerTournamentSummaryGame;
use App\ApiResource\PlayerTournamentSummary\PlayerTournamentSummaryStats;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerTournamentSummaryService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    public function getSummary(int $tournamentId, int $playerId): PlayerTournamentSummary
    {
        $playerTournamentRow = $this->connection->fetchAssociative(
            "SELECT
                tw.place,
                tw.trank AS rankAchieved,
                tw.player,
                p.name_show AS playerName,
                COALESCE(t.fullname, t.name) AS tournamentName
            FROM PFSTOURWYN tw
            INNER JOIN PFSPLAYER p ON p.id = tw.player
            INNER JOIN PFSTOURS t ON t.id = tw.turniej
            WHERE tw.turniej = :tournamentId
              AND tw.player = :playerId",
            [
                'tournamentId' => $tournamentId,
                'playerId' => $playerId,
            ]
        );

        if ($playerTournamentRow === false) {
            throw new NotFoundHttpException(sprintf('No summary for player %d in tournament %d.', $playerId, $tournamentId));
        }

        $gamesRaw = $this->connection->fetchAllAssociative(
            "WITH ranked_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.stol,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    h.host,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY CASE WHEN h.player1 = :playerId THEN 0 ELSE 1 END, h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                WHERE h.turniej = :tournamentId
                  AND (h.player1 = :playerId OR h.player2 = :playerId)
            )
            SELECT
                rg.runda AS roundNo,
                rg.stol AS tableNo,
                rg.player1,
                rg.player2,
                rg.result1,
                rg.result2,
                rg.host,
                p.name_show AS opponentName,
                twOpponent.brank AS opponentRank
            FROM ranked_games rg
            INNER JOIN PFSPLAYER p ON p.id = CASE WHEN rg.player1 = :playerId THEN rg.player2 ELSE rg.player1 END
            LEFT JOIN PFSTOURWYN twOpponent
                ON twOpponent.turniej = rg.turniej
               AND twOpponent.player = CASE WHEN rg.player1 = :playerId THEN rg.player2 ELSE rg.player1 END
            WHERE rg.rn = 1
            ORDER BY rg.runda ASC, rg.stol ASC, rg.player1 ASC, rg.player2 ASC",
            [
                'tournamentId' => $tournamentId,
                'playerId' => $playerId,
            ]
        );

        if ($gamesRaw === []) {
            throw new NotFoundHttpException(sprintf('No games for player %d in tournament %d.', $playerId, $tournamentId));
        }

        $games = [];
        $sumPoints = 0;
        $sumOpponentPoints = 0;
        $sumOpponentRank = 0.0;
        $wonPoints = 0;
        $wonOpponentPoints = 0;
        $wonDiff = 0;
        $wonGames = 0;
        $lostPoints = 0;
        $lostOpponentPoints = 0;
        $lostDiff = 0;
        $lostGames = 0;

        foreach ($gamesRaw as $row) {
            $isPlayer1Perspective = (int) $row['player1'] === $playerId;
            $wasFirstToPlay = $row['host'] !== null
                ? (int) $row['host'] === $playerId
                : $isPlayer1Perspective;
            $points = $isPlayer1Perspective ? (int) $row['result1'] : (int) $row['result2'];
            $pointsLost = $isPlayer1Perspective ? (int) $row['result2'] : (int) $row['result1'];
            $opponentRank = (float) ($row['opponentRank'] ?? 100.0);

            $result = 'draw';
            if ($points > $pointsLost) {
                $result = 'win';
            } elseif ($points < $pointsLost) {
                $result = 'lose';
            }

            $scalp = match ($result) {
                'win' => $opponentRank + 50.0,
                'lose' => $opponentRank - 50.0,
                default => $opponentRank,
            };

            $sumPoints += $points;
            $sumOpponentPoints += $pointsLost;
            $sumOpponentRank += $opponentRank;

            if ($result === 'win') {
                $wonGames++;
                $wonPoints += $points;
                $wonOpponentPoints += $pointsLost;
                $wonDiff += ($points - $pointsLost);
            } elseif ($result === 'lose') {
                $lostGames++;
                $lostPoints += $points;
                $lostOpponentPoints += $pointsLost;
                $lostDiff += ($points - $pointsLost);
            }

            $games[] = new PlayerTournamentSummaryGame(
                round: (int) $row['roundNo'],
                tableNumber: $row['tableNo'] !== null ? (int) $row['tableNo'] : null,
                wasFirstToPlay: $wasFirstToPlay,
                result: $result,
                opponentId: (int) ($isPlayer1Perspective ? $row['player2'] : $row['player1']),
                opponentName: (string) $row['opponentName'],
                achievedRank: round($scalp, 2),
                points: $points,
                pointsLost: $pointsLost,
                pointsSum: $points + $pointsLost,
                scalp: round($scalp, 2),
            );
        }

        $gamesCount = count($games);

        $stats = new PlayerTournamentSummaryStats(
            position: (int) $playerTournamentRow['place'],
            rankAchieved: round((float) $playerTournamentRow['rankAchieved'], 2),
            avgOpponentRank: round($sumOpponentRank / $gamesCount, 2),
            avgPointsPerGame: round($sumPoints / $gamesCount, 2),
            avgOpponentPointsPerGame: round($sumOpponentPoints / $gamesCount, 2),
            avgPointsPerGameWon: $wonGames > 0 ? round($wonPoints / $wonGames, 2) : 0.0,
            avgOpponentPointsPerGameWon: $wonGames > 0 ? round($wonOpponentPoints / $wonGames, 2) : 0.0,
            avgPointsPerGameLost: $lostGames > 0 ? round($lostPoints / $lostGames, 2) : 0.0,
            avgOpponentPointsPerGameLost: $lostGames > 0 ? round($lostOpponentPoints / $lostGames, 2) : 0.0,
            avgPointsSum: round(($sumPoints + $sumOpponentPoints) / $gamesCount, 2),
            avgDiffWon: $wonGames > 0 ? round($wonDiff / $wonGames, 2) : 0.0,
            avgDiffLost: $lostGames > 0 ? round($lostDiff / $lostGames, 2) : 0.0,
        );

        return new PlayerTournamentSummary(
            tournamentId: $tournamentId,
            playerId: $playerId,
            playerName: (string) $playerTournamentRow['playerName'],
            tournamentName: (string) $playerTournamentRow['tournamentName'],
            stats: $stats,
            games: $games,
        );
    }
}
