<?php

namespace App\Service\PlayerTournamentSummary;

use App\ApiResource\PlayerTournamentSummary\PlayerTournamentSummary;
use App\ApiResource\PlayerTournamentSummary\PlayerTournamentSummaryGame;
use App\ApiResource\PlayerTournamentSummary\PlayerTournamentSummaryStats;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerTournamentSummaryServicePostgres implements PlayerTournamentSummaryServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function getSummary(int $tournamentId, int $playerId): PlayerTournamentSummary
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            throw new NotFoundHttpException(sprintf('No summary for player %d in tournament %d.', $playerId, $tournamentId));
        }

        $playerTournamentRow = $this->connection->fetchAssociative(
            "SELECT
                tw.place,
                tw.trank AS rank_achieved,
                tw.legacy_player_id AS player,
                p.name_show AS player_name,
                COALESCE(t.fullname, t.name) AS tournament_name
            FROM tournament_result tw
            INNER JOIN player p ON p.id = tw.player_id
            INNER JOIN tournament t
                ON t.organization_id = tw.organization_id
               AND t.legacy_id = tw.legacy_tournament_id
            WHERE tw.organization_id = :organizationId
              AND tw.legacy_tournament_id = :tournamentId
              AND tw.legacy_player_id = :playerId",
            [
                'organizationId' => $organizationId,
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
                    h.legacy_tournament_id AS turniej,
                    h.round_no AS runda,
                    h.table_no AS stol,
                    h.legacy_player1_id AS player1,
                    h.legacy_player2_id AS player2,
                    h.player1_id AS player1_pk,
                    h.player2_id AS player2_pk,
                    h.result1,
                    h.result2,
                    h.host,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.round_no, LEAST(h.legacy_player1_id, h.legacy_player2_id), GREATEST(h.legacy_player1_id, h.legacy_player2_id)
                        ORDER BY CASE WHEN h.legacy_player1_id = :playerId THEN 0 ELSE 1 END, h.legacy_player1_id ASC
                    ) AS rn
                FROM tournament_game h
                WHERE h.organization_id = :organizationId
                  AND h.legacy_tournament_id = :tournamentId
                  AND (h.legacy_player1_id = :playerId OR h.legacy_player2_id = :playerId)
            )
            SELECT
                rg.runda AS round_no,
                rg.stol AS table_no,
                rg.player1,
                rg.player2,
                rg.result1,
                rg.result2,
                rg.host,
                p.name_show AS opponent_name,
                tw_opponent.brank AS opponent_rank
            FROM ranked_games rg
            INNER JOIN player p ON p.id = CASE WHEN rg.player1 = :playerId THEN rg.player2_pk ELSE rg.player1_pk END
            LEFT JOIN tournament_result tw_opponent
                ON tw_opponent.organization_id = :organizationId
               AND tw_opponent.legacy_tournament_id = rg.turniej
               AND tw_opponent.legacy_player_id = CASE WHEN rg.player1 = :playerId THEN rg.player2 ELSE rg.player1 END
            WHERE rg.rn = 1
            ORDER BY rg.runda ASC, rg.stol ASC, rg.player1 ASC, rg.player2 ASC",
            [
                'organizationId' => $organizationId,
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
            $opponentRank = (float) ($row['opponent_rank'] ?? 100.0);

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
                round: (int) $row['round_no'],
                tableNumber: $row['table_no'] !== null ? (int) $row['table_no'] : null,
                wasFirstToPlay: $wasFirstToPlay,
                result: $result,
                opponentId: (int) ($isPlayer1Perspective ? $row['player2'] : $row['player1']),
                opponentName: (string) $row['opponent_name'],
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
            rankAchieved: round((float) $playerTournamentRow['rank_achieved'], 2),
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
            playerName: (string) $playerTournamentRow['player_name'],
            tournamentName: (string) $playerTournamentRow['tournament_name'],
            stats: $stats,
            games: $games,
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
}
