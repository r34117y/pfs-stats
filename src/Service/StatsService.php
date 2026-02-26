<?php

namespace App\Service;

use App\ApiResource\Stats\AllTimeSummary;
use App\ApiResource\Stats\AllTimeSummaryRow;
use App\ApiResource\Stats\AllTimesResults;
use App\ApiResource\Stats\AllTimesResultsPlayer;
use App\ApiResource\Stats\AvgOpponentsPointsPerGame;
use App\ApiResource\Stats\AvgOpponentsPointsPerGameRow;
use App\ApiResource\Stats\AvgPointsSumPerGame;
use App\ApiResource\Stats\AvgPointsSumPerGameRow;
use App\ApiResource\Stats\AvgPointsDifferencePerGame;
use App\ApiResource\Stats\AvgPointsDifferencePerGameRow;
use App\ApiResource\Stats\AvgPointsPerGame;
use App\ApiResource\Stats\AvgPointsPerGameRow;
use App\ApiResource\Stats\GamesCount;
use App\ApiResource\Stats\GamesCountRow;
use App\ApiResource\Stats\GamesOver400;
use App\ApiResource\Stats\GamesOver400Row;
use App\ApiResource\Stats\DifferentOpponents;
use App\ApiResource\Stats\DifferentOpponentsRow;
use App\ApiResource\Stats\MostSmallPoints;
use App\ApiResource\Stats\MostSmallPointsRow;
use App\ApiResource\Stats\LeastSmallPoints;
use App\ApiResource\Stats\LeastSmallPointsRow;
use App\ApiResource\Stats\HighestPointsSum;
use App\ApiResource\Stats\HighestPointsSumRow;
use App\ApiResource\Stats\LowestPointsSum;
use App\ApiResource\Stats\LowestPointsSumRow;
use App\ApiResource\Stats\HighestVictory;
use App\ApiResource\Stats\HighestVictoryRow;
use App\ApiResource\Stats\HighestDraw;
use App\ApiResource\Stats\HighestDrawRow;
use App\ApiResource\Stats\MostPointsAndLoss;
use App\ApiResource\Stats\MostPointsAndLossRow;
use App\ApiResource\Stats\LeastPointsAndWin;
use App\ApiResource\Stats\LeastPointsAndWinRow;
use App\ApiResource\Stats\MostOpponentPointsAndWin;
use App\ApiResource\Stats\MostOpponentPointsAndWinRow;
use App\ApiResource\Stats\LeastOpponentPointsAndLoss;
use App\ApiResource\Stats\LeastOpponentPointsAndLossRow;
use App\ApiResource\Stats\RankAllGames;
use App\ApiResource\Stats\RankAllGamesRow;
use App\ApiResource\Stats\HighestRank;
use App\ApiResource\Stats\HighestRankRow;
use App\ApiResource\Stats\HighestRankPosition;
use App\ApiResource\Stats\HighestRankPositionRow;
use App\ApiResource\Stats\RankingLeaders;
use App\ApiResource\Stats\RankingLeadersRow;
use App\ApiResource\Stats\GamesWon;
use App\ApiResource\Stats\GamesWonRow;
use App\ApiResource\Stats\TournamentsCount;
use App\ApiResource\Stats\TournamentsCountRow;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class StatsService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    public function getAllTimesResults(): AllTimesResults
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                tw.player AS playerId,
                p.name_show AS playerName,
                SUM(CASE WHEN tw.place = 1 THEN 1 ELSE 0 END) AS firstPlace,
                SUM(CASE WHEN tw.place = 2 THEN 1 ELSE 0 END) AS secondPlace,
                SUM(CASE WHEN tw.place = 3 THEN 1 ELSE 0 END) AS thirdPlace,
                SUM(CASE WHEN tw.place = 4 THEN 1 ELSE 0 END) AS fourthPlace,
                SUM(CASE WHEN tw.place = 5 THEN 1 ELSE 0 END) AS fifthPlace,
                SUM(CASE WHEN tw.place = 6 THEN 1 ELSE 0 END) AS sixthPlace
            FROM PFSTOURWYN tw
            INNER JOIN PFSPLAYER p ON p.id = tw.player
            WHERE tw.place BETWEEN 1 AND 6
            GROUP BY tw.player, p.name_show
            ORDER BY
                firstPlace DESC,
                secondPlace DESC,
                thirdPlace DESC,
                fourthPlace DESC,
                fifthPlace DESC,
                sixthPlace DESC,
                p.name_show ASC"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $first = (int) $row['firstPlace'];
            $second = (int) $row['secondPlace'];
            $third = (int) $row['thirdPlace'];
            $fourth = (int) $row['fourthPlace'];
            $fifth = (int) $row['fifthPlace'];
            $sixth = (int) $row['sixthPlace'];

            $resultRows[] = new AllTimesResultsPlayer(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                first: $first,
                second: $second,
                third: $third,
                fourth: $fourth,
                fifth: $fifth,
                sixth: $sixth,
                oneToThree: $first + $second + $third,
                oneToSix: $first + $second + $third + $fourth + $fifth + $sixth,
            );
        }

        return new AllTimesResults($resultRows);
    }

    public function getAllTimeSummary(): AllTimeSummary
    {
        $today = new DateTimeImmutable('today');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $allTime = $this->calculateSummaryMetrics(null);
        $last12Months = $this->calculateSummaryMetrics($last12MonthsDateInt);

        $rows = [
            $this->buildSummaryRow('Liczba turniejów', $allTime['tournamentsCount'], $last12Months['tournamentsCount']),
            $this->buildSummaryRow('Suma uczestników turniejów', $allTime['sumParticipants'], $last12Months['sumParticipants']),
            $this->buildSummaryRow('Średni ranking turniejów', $this->formatFloat($allTime['avgTournamentRank']), $this->formatFloat($last12Months['avgTournamentRank'])),
            $this->buildSummaryRow('Grających zawodników', $allTime['activePlayers'], $last12Months['activePlayers']),
            $this->buildSummaryRow('Na liście rankingowej', $allTime['rankingListedPlayers'], $last12Months['rankingListedPlayers']),
            $this->buildSummaryRow('Rozegrane gry', $allTime['playedGames'], $last12Months['playedGames']),
            $this->buildSummaryRow('Odsetek remisów', $this->formatPercent($allTime['drawPercent']), $this->formatPercent($last12Months['drawPercent'])),
            $this->buildSummaryRow('Średnia ilość graczy na turnieju', $this->formatFloat($allTime['avgPlayersPerTournament']), $this->formatFloat($last12Months['avgPlayersPerTournament'])),
            $this->buildSummaryRow('Gier na zawodnika', $this->formatFloat($allTime['gamesPerPlayer']), $this->formatFloat($last12Months['gamesPerPlayer'])),
            $this->buildSummaryRow('Gier powyżej 350 punktów (%)', $this->formatPercent($allTime['over350Percent']), $this->formatPercent($last12Months['over350Percent'])),
            $this->buildSummaryRow('Gier powyżej 400 punktów (%)', $this->formatPercent($allTime['over400Percent']), $this->formatPercent($last12Months['over400Percent'])),
            $this->buildSummaryRow('Gier powyżej 500 punktów (%)', $this->formatPercent($allTime['over500Percent']), $this->formatPercent($last12Months['over500Percent'])),
            $this->buildSummaryRow('Gier powyżej 600 punktów (%)', $this->formatPercent($allTime['over600Percent']), $this->formatPercent($last12Months['over600Percent'])),
            $this->buildSummaryRow('Średnia punktów zwycięzcy', $this->formatFloat($allTime['avgWinnerPoints']), $this->formatFloat($last12Months['avgWinnerPoints'])),
            $this->buildSummaryRow('Średnia punktów pokonanego', $this->formatFloat($allTime['avgLoserPoints']), $this->formatFloat($last12Months['avgLoserPoints'])),
            $this->buildSummaryRow('Wygrane gracza powyżej 130 z graczem 110-130', $allTime['wins130PlusVs110to130'], $last12Months['wins130PlusVs110to130']),
            $this->buildSummaryRow('Wygrane gracza powyżej 130 z graczem poniżej 110', $allTime['wins130PlusVsBelow110'], $last12Months['wins130PlusVsBelow110']),
            $this->buildSummaryRow('Wygrane gracza 110-130 z graczem poniżej 110', $allTime['wins110to130VsBelow110'], $last12Months['wins110to130VsBelow110']),
            $this->buildSummaryRow('Odsetek gier wygranych przez gospodarza', $this->formatPercent($allTime['hostWinPercent']), $this->formatPercent($last12Months['hostWinPercent'])),
        ];

        return new AllTimeSummary($rows);
    }

    public function getGamesCount(): GamesCount
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
            ),
            player_games AS (
                SELECT ug.player1 AS player_id, ug.dt
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT ug.player2 AS player_id, ug.dt
                FROM unique_games ug
                WHERE ug.rn = 1
            )
            SELECT
                p.id AS playerId,
                p.name_show AS playerName,
                COUNT(pg.player_id) AS gamesCount,
                SUM(CASE WHEN pg.dt >= :last24MonthsDate THEN 1 ELSE 0 END) AS last24MonthsGamesCount,
                SUM(CASE WHEN pg.dt >= :last12MonthsDate THEN 1 ELSE 0 END) AS last12MonthsGamesCount
            FROM PFSPLAYER p
            LEFT JOIN player_games pg ON pg.player_id = p.id
            GROUP BY p.id, p.name_show
            HAVING COUNT(pg.player_id) > 0
            ORDER BY gamesCount DESC, playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new GamesCountRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                gamesCount: (int) $row['gamesCount'],
                last24MonthsGamesCount: (int) $row['last24MonthsGamesCount'],
                last12MonthsGamesCount: (int) $row['last12MonthsGamesCount'],
            );
        }

        return new GamesCount($resultRows);
    }

    public function getGamesWon(): GamesWon
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
            ),
            player_games AS (
                SELECT
                    ug.player1 AS player_id,
                    ug.dt,
                    CASE WHEN ug.result1 > ug.result2 THEN 1 ELSE 0 END AS is_win
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT
                    ug.player2 AS player_id,
                    ug.dt,
                    CASE WHEN ug.result2 > ug.result1 THEN 1 ELSE 0 END AS is_win
                FROM unique_games ug
                WHERE ug.rn = 1
            )
            SELECT
                p.id AS playerId,
                p.name_show AS playerName,
                COUNT(pg.player_id) AS gamesCount,
                COALESCE(SUM(pg.is_win), 0) AS gamesWon,
                SUM(CASE WHEN pg.dt >= :last24MonthsDate THEN 1 ELSE 0 END) AS gamesCount24Months,
                SUM(CASE WHEN pg.dt >= :last24MonthsDate THEN pg.is_win ELSE 0 END) AS gamesWon24Months,
                SUM(CASE WHEN pg.dt >= :last12MonthsDate THEN 1 ELSE 0 END) AS gamesCount12Months,
                SUM(CASE WHEN pg.dt >= :last12MonthsDate THEN pg.is_win ELSE 0 END) AS gamesWon12Months
            FROM PFSPLAYER p
            LEFT JOIN player_games pg ON pg.player_id = p.id
            GROUP BY p.id, p.name_show
            HAVING COUNT(pg.player_id) > 0
            ORDER BY gamesWon DESC, playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $gamesCount = (int) $row['gamesCount'];
            $gamesWon = (int) $row['gamesWon'];
            $gamesCount24Months = (int) $row['gamesCount24Months'];
            $gamesWon24Months = (int) $row['gamesWon24Months'];
            $gamesCount12Months = (int) $row['gamesCount12Months'];
            $gamesWon12Months = (int) $row['gamesWon12Months'];

            $resultRows[] = new GamesWonRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                gamesWon: $gamesWon,
                gamesWonPercent: $gamesCount > 0 ? round(($gamesWon * 100.0) / $gamesCount, 2) : 0.0,
                gamesWon24Months: $gamesWon24Months,
                gamesWon24MonthsPercent: $gamesCount24Months > 0 ? round(($gamesWon24Months * 100.0) / $gamesCount24Months, 2) : 0.0,
                gamesWon12Months: $gamesWon12Months,
                gamesWon12MonthsPercent: $gamesCount12Months > 0 ? round(($gamesWon12Months * 100.0) / $gamesCount12Months, 2) : 0.0,
            );
        }

        return new GamesWon($resultRows);
    }

    public function getTournamentsCount(): TournamentsCount
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                tw.player AS playerId,
                p.name_show AS playerName,
                COUNT(DISTINCT tw.turniej) AS tournamentsCount,
                COUNT(DISTINCT CASE WHEN t.dt >= :last24MonthsDate THEN tw.turniej ELSE NULL END) AS last24MonthsTournamentsCount,
                COUNT(DISTINCT CASE WHEN t.dt >= :last12MonthsDate THEN tw.turniej ELSE NULL END) AS last12MonthsTournamentsCount
            FROM PFSTOURWYN tw
            INNER JOIN PFSPLAYER p ON p.id = tw.player
            INNER JOIN PFSTOURS t ON t.id = tw.turniej
            GROUP BY tw.player, p.name_show
            ORDER BY tournamentsCount DESC, playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new TournamentsCountRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                tournamentsCount: (int) $row['tournamentsCount'],
                last24MonthsTournamentsCount: (int) $row['last24MonthsTournamentsCount'],
                last12MonthsTournamentsCount: (int) $row['last12MonthsTournamentsCount'],
            );
        }

        return new TournamentsCount($resultRows);
    }

    public function getAvgPointsPerGame(): AvgPointsPerGame
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
            ),
            player_scores AS (
                SELECT ug.player1 AS player_id, ug.dt, ug.result1 AS score
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT ug.player2 AS player_id, ug.dt, ug.result2 AS score
                FROM unique_games ug
                WHERE ug.rn = 1
            )
            SELECT
                p.id AS playerId,
                p.name_show AS playerName,
                AVG(ps.score) AS averagePoints,
                AVG(CASE WHEN ps.dt >= :last24MonthsDate THEN ps.score ELSE NULL END) AS last24MonthsAveragePoints,
                AVG(CASE WHEN ps.dt >= :last12MonthsDate THEN ps.score ELSE NULL END) AS last12MonthsAveragePoints
            FROM PFSPLAYER p
            LEFT JOIN player_scores ps ON ps.player_id = p.id
            GROUP BY p.id, p.name_show
            HAVING COUNT(ps.player_id) > 0
            ORDER BY averagePoints DESC, playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new AvgPointsPerGameRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                averagePoints: round((float) ($row['averagePoints'] ?? 0.0), 2),
                last24MonthsAveragePoints: round((float) ($row['last24MonthsAveragePoints'] ?? 0.0), 2),
                last12MonthsAveragePoints: round((float) ($row['last12MonthsAveragePoints'] ?? 0.0), 2),
            );
        }

        return new AvgPointsPerGame($resultRows);
    }

    public function getAvgOpponentsPointsPerGame(): AvgOpponentsPointsPerGame
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
            ),
            player_opponent_scores AS (
                SELECT ug.player1 AS player_id, ug.dt, ug.result2 AS opponent_score
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT ug.player2 AS player_id, ug.dt, ug.result1 AS opponent_score
                FROM unique_games ug
                WHERE ug.rn = 1
            )
            SELECT
                p.id AS playerId,
                p.name_show AS playerName,
                AVG(pos.opponent_score) AS averageOpponentPoints,
                CASE
                    WHEN SUM(CASE WHEN pos.dt >= :last24MonthsDate THEN 1 ELSE 0 END) >= 30
                        THEN AVG(CASE WHEN pos.dt >= :last24MonthsDate THEN pos.opponent_score ELSE NULL END)
                    ELSE NULL
                END AS last24MonthsAverageOpponentPoints,
                CASE
                    WHEN SUM(CASE WHEN pos.dt >= :last12MonthsDate THEN 1 ELSE 0 END) >= 30
                        THEN AVG(CASE WHEN pos.dt >= :last12MonthsDate THEN pos.opponent_score ELSE NULL END)
                    ELSE NULL
                END AS last12MonthsAverageOpponentPoints
            FROM PFSPLAYER p
            LEFT JOIN player_opponent_scores pos ON pos.player_id = p.id
            GROUP BY p.id, p.name_show
            HAVING COUNT(pos.player_id) >= 30
            ORDER BY last24MonthsAverageOpponentPoints DESC, playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new AvgOpponentsPointsPerGameRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                averageOpponentPoints: round((float) ($row['averageOpponentPoints'] ?? 0.0), 2),
                last24MonthsAverageOpponentPoints: $row['last24MonthsAverageOpponentPoints'] === null
                    ? null
                    : round((float) $row['last24MonthsAverageOpponentPoints'], 2),
                last12MonthsAverageOpponentPoints: $row['last12MonthsAverageOpponentPoints'] === null
                    ? null
                    : round((float) $row['last12MonthsAverageOpponentPoints'], 2),
            );
        }

        return new AvgOpponentsPointsPerGame($resultRows);
    }

    public function getAvgPointsSumPerGame(): AvgPointsSumPerGame
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
            ),
            player_sum_scores AS (
                SELECT ug.player1 AS player_id, ug.dt, (ug.result1 + ug.result2) AS points_sum
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT ug.player2 AS player_id, ug.dt, (ug.result1 + ug.result2) AS points_sum
                FROM unique_games ug
                WHERE ug.rn = 1
            )
            SELECT
                p.id AS playerId,
                p.name_show AS playerName,
                AVG(ps.points_sum) AS averagePointsSum,
                CASE
                    WHEN SUM(CASE WHEN ps.dt >= :last24MonthsDate THEN 1 ELSE 0 END) >= 30
                        THEN AVG(CASE WHEN ps.dt >= :last24MonthsDate THEN ps.points_sum ELSE NULL END)
                    ELSE NULL
                END AS last24MonthsAveragePointsSum,
                CASE
                    WHEN SUM(CASE WHEN ps.dt >= :last12MonthsDate THEN 1 ELSE 0 END) >= 30
                        THEN AVG(CASE WHEN ps.dt >= :last12MonthsDate THEN ps.points_sum ELSE NULL END)
                    ELSE NULL
                END AS last12MonthsAveragePointsSum
            FROM PFSPLAYER p
            LEFT JOIN player_sum_scores ps ON ps.player_id = p.id
            GROUP BY p.id, p.name_show
            HAVING COUNT(ps.player_id) >= 30
            ORDER BY last24MonthsAveragePointsSum DESC, playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new AvgPointsSumPerGameRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                averagePointsSum: round((float) ($row['averagePointsSum'] ?? 0.0), 2),
                last24MonthsAveragePointsSum: $row['last24MonthsAveragePointsSum'] === null
                    ? null
                    : round((float) $row['last24MonthsAveragePointsSum'], 2),
                last12MonthsAveragePointsSum: $row['last12MonthsAveragePointsSum'] === null
                    ? null
                    : round((float) $row['last12MonthsAveragePointsSum'], 2),
            );
        }

        return new AvgPointsSumPerGame($resultRows);
    }

    public function getAvgPointsDifferencePerGame(): AvgPointsDifferencePerGame
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
            ),
            player_diff_scores AS (
                SELECT ug.player1 AS player_id, ug.dt, (ug.result1 - ug.result2) AS points_diff
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT ug.player2 AS player_id, ug.dt, (ug.result2 - ug.result1) AS points_diff
                FROM unique_games ug
                WHERE ug.rn = 1
            )
            SELECT
                p.id AS playerId,
                p.name_show AS playerName,
                AVG(pds.points_diff) AS averagePointsDifference,
                CASE
                    WHEN SUM(CASE WHEN pds.dt >= :last24MonthsDate THEN 1 ELSE 0 END) >= 30
                        THEN AVG(CASE WHEN pds.dt >= :last24MonthsDate THEN pds.points_diff ELSE NULL END)
                    ELSE NULL
                END AS last24MonthsAveragePointsDifference,
                CASE
                    WHEN SUM(CASE WHEN pds.dt >= :last12MonthsDate THEN 1 ELSE 0 END) >= 30
                        THEN AVG(CASE WHEN pds.dt >= :last12MonthsDate THEN pds.points_diff ELSE NULL END)
                    ELSE NULL
                END AS last12MonthsAveragePointsDifference
            FROM PFSPLAYER p
            LEFT JOIN player_diff_scores pds ON pds.player_id = p.id
            GROUP BY p.id, p.name_show
            HAVING COUNT(pds.player_id) >= 30
            ORDER BY last24MonthsAveragePointsDifference DESC, playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new AvgPointsDifferencePerGameRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                averagePointsDifference: round((float) ($row['averagePointsDifference'] ?? 0.0), 2),
                last24MonthsAveragePointsDifference: $row['last24MonthsAveragePointsDifference'] === null
                    ? null
                    : round((float) $row['last24MonthsAveragePointsDifference'], 2),
                last12MonthsAveragePointsDifference: $row['last12MonthsAveragePointsDifference'] === null
                    ? null
                    : round((float) $row['last12MonthsAveragePointsDifference'], 2),
            );
        }

        return new AvgPointsDifferencePerGame($resultRows);
    }

    public function getGamesOver400(): GamesOver400
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
            ),
            player_scores AS (
                SELECT ug.player1 AS player_id, ug.dt, ug.result1 AS score
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT ug.player2 AS player_id, ug.dt, ug.result2 AS score
                FROM unique_games ug
                WHERE ug.rn = 1
            ),
            stats AS (
                SELECT
                    p.id AS playerId,
                    p.name_show AS playerName,
                    COUNT(ps.player_id) AS gamesCount,
                    SUM(CASE WHEN ps.score > 400 THEN 1 ELSE 0 END) AS gamesOver400,
                    SUM(CASE WHEN ps.dt >= :last24MonthsDate THEN 1 ELSE 0 END) AS gamesCount24Months,
                    SUM(CASE WHEN ps.dt >= :last24MonthsDate AND ps.score > 400 THEN 1 ELSE 0 END) AS gamesOver40024Months,
                    SUM(CASE WHEN ps.dt >= :last12MonthsDate THEN 1 ELSE 0 END) AS gamesCount12Months,
                    SUM(CASE WHEN ps.dt >= :last12MonthsDate AND ps.score > 400 THEN 1 ELSE 0 END) AS gamesOver40012Months
                FROM PFSPLAYER p
                LEFT JOIN player_scores ps ON ps.player_id = p.id
                GROUP BY p.id, p.name_show
                HAVING COUNT(ps.player_id) >= 30
            )
            SELECT
                s.playerId,
                s.playerName,
                s.gamesOver400,
                (s.gamesOver400 * 100.0 / s.gamesCount) AS gamesOver400Percent,
                CASE
                    WHEN s.gamesCount24Months >= 30 THEN s.gamesOver40024Months
                    ELSE NULL
                END AS gamesOver40024Months,
                CASE
                    WHEN s.gamesCount24Months >= 30 THEN (s.gamesOver40024Months * 100.0 / s.gamesCount24Months)
                    ELSE NULL
                END AS gamesOver40024MonthsPercent,
                CASE
                    WHEN s.gamesCount12Months >= 30 THEN s.gamesOver40012Months
                    ELSE NULL
                END AS gamesOver40012Months,
                CASE
                    WHEN s.gamesCount12Months >= 30 THEN (s.gamesOver40012Months * 100.0 / s.gamesCount12Months)
                    ELSE NULL
                END AS gamesOver40012MonthsPercent
            FROM stats s
            ORDER BY gamesOver40024MonthsPercent DESC, playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new GamesOver400Row(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                gamesOver400: (int) $row['gamesOver400'],
                gamesOver400Percent: round((float) $row['gamesOver400Percent'], 2),
                gamesOver40024Months: $row['gamesOver40024Months'] === null ? null : (int) $row['gamesOver40024Months'],
                gamesOver40024MonthsPercent: $row['gamesOver40024MonthsPercent'] === null
                    ? null
                    : round((float) $row['gamesOver40024MonthsPercent'], 2),
                gamesOver40012Months: $row['gamesOver40012Months'] === null ? null : (int) $row['gamesOver40012Months'],
                gamesOver40012MonthsPercent: $row['gamesOver40012MonthsPercent'] === null
                    ? null
                    : round((float) $row['gamesOver40012MonthsPercent'], 2),
            );
        }

        return new GamesOver400($resultRows);
    }

    public function getRankAllGames(): RankAllGames
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    t.dt,
                    COALESCE(tw1.brank, 100) AS rank1,
                    COALESCE(tw2.brank, 100) AS rank2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
                LEFT JOIN PFSTOURWYN tw1 ON tw1.turniej = h.turniej AND tw1.player = h.player1
                LEFT JOIN PFSTOURWYN tw2 ON tw2.turniej = h.turniej AND tw2.player = h.player2
            ),
            player_game_rank AS (
                SELECT
                    ug.player1 AS player_id,
                    ug.dt,
                    CASE
                        WHEN ug.result1 > ug.result2 THEN (ug.rank2 + 50)
                        WHEN ug.result1 < ug.result2 THEN (ug.rank2 - 50)
                        ELSE ug.rank2
                    END AS achieved_rank
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT
                    ug.player2 AS player_id,
                    ug.dt,
                    CASE
                        WHEN ug.result2 > ug.result1 THEN (ug.rank1 + 50)
                        WHEN ug.result2 < ug.result1 THEN (ug.rank1 - 50)
                        ELSE ug.rank1
                    END AS achieved_rank
                FROM unique_games ug
                WHERE ug.rn = 1
            ),
            stats AS (
                SELECT
                    p.id AS playerId,
                    p.name_show AS playerName,
                    COUNT(pgr.player_id) AS gamesCount,
                    AVG(pgr.achieved_rank) AS rankAllGames,
                    SUM(CASE WHEN pgr.dt >= :last24MonthsDate THEN 1 ELSE 0 END) AS gamesCount24Months,
                    AVG(CASE WHEN pgr.dt >= :last24MonthsDate THEN pgr.achieved_rank ELSE NULL END) AS rank24Months,
                    SUM(CASE WHEN pgr.dt >= :last12MonthsDate THEN 1 ELSE 0 END) AS gamesCount12Months,
                    AVG(CASE WHEN pgr.dt >= :last12MonthsDate THEN pgr.achieved_rank ELSE NULL END) AS rank12Months
                FROM PFSPLAYER p
                LEFT JOIN player_game_rank pgr ON pgr.player_id = p.id
                GROUP BY p.id, p.name_show
                HAVING COUNT(pgr.player_id) >= 30
            )
            SELECT
                s.playerId,
                s.playerName,
                s.rankAllGames,
                CASE WHEN s.gamesCount24Months >= 30 THEN s.rank24Months ELSE NULL END AS rank24Months,
                CASE WHEN s.gamesCount12Months >= 30 THEN s.rank12Months ELSE NULL END AS rank12Months
            FROM stats s
            ORDER BY rank24Months DESC, playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new RankAllGamesRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                rankAllGames: round((float) ($row['rankAllGames'] ?? 0.0), 2),
                rank24Months: $row['rank24Months'] === null ? null : round((float) $row['rank24Months'], 2),
                rank12Months: $row['rank12Months'] === null ? null : round((float) $row['rank12Months'], 2),
            );
        }

        return new RankAllGames($resultRows);
    }

    public function getHighestRank(): HighestRank
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
            ),
            player_games AS (
                SELECT ug.player1 AS player_id, ug.dt
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT ug.player2 AS player_id, ug.dt
                FROM unique_games ug
                WHERE ug.rn = 1
            ),
            games_stats AS (
                SELECT
                    p.id AS playerId,
                    p.name_show AS playerName,
                    COUNT(pg.player_id) AS gamesCount,
                    SUM(CASE WHEN pg.dt >= :last24MonthsDate THEN 1 ELSE 0 END) AS gamesCount24Months,
                    SUM(CASE WHEN pg.dt >= :last12MonthsDate THEN 1 ELSE 0 END) AS gamesCount12Months
                FROM PFSPLAYER p
                LEFT JOIN player_games pg ON pg.player_id = p.id
                GROUP BY p.id, p.name_show
                HAVING COUNT(pg.player_id) >= 30
            ),
            ranking_stats AS (
                SELECT
                    r.player AS playerId,
                    MAX(r.rank) AS highestRank,
                    MAX(CASE WHEN t.dt >= :last24MonthsDate THEN r.rank ELSE NULL END) AS highestRank24MonthsRaw,
                    MAX(CASE WHEN t.dt >= :last12MonthsDate THEN r.rank ELSE NULL END) AS highestRank12MonthsRaw
                FROM PFSRANKING r
                INNER JOIN PFSTOURS t ON t.id = r.turniej
                WHERE r.rtype = 'f'
                GROUP BY r.player
            )
            SELECT
                gs.playerId,
                gs.playerName,
                rs.highestRank AS highestRank,
                CASE
                    WHEN gs.gamesCount24Months >= 30 THEN rs.highestRank24MonthsRaw
                    ELSE NULL
                END AS highestRank24Months,
                CASE
                    WHEN gs.gamesCount12Months >= 30 THEN rs.highestRank12MonthsRaw
                    ELSE NULL
                END AS highestRank12Months
            FROM games_stats gs
            INNER JOIN ranking_stats rs ON rs.playerId = gs.playerId
            ORDER BY highestRank24Months DESC, gs.playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new HighestRankRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                highestRank: round((float) ($row['highestRank'] ?? 0.0), 2),
                highestRank24Months: $row['highestRank24Months'] === null ? null : round((float) $row['highestRank24Months'], 2),
                highestRank12Months: $row['highestRank12Months'] === null ? null : round((float) $row['highestRank12Months'], 2),
            );
        }

        return new HighestRank($resultRows);
    }


    public function getHighestRankPosition(): HighestRankPosition
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "WITH ranking_positions AS (
                SELECT
                    r.player AS playerId,
                    p.name_show AS playerName,
                    r.pos AS rankPosition,
                    t.dt
                FROM PFSRANKING r
                INNER JOIN PFSTOURS t ON t.id = r.turniej
                INNER JOIN PFSPLAYER p ON p.id = r.player
                WHERE r.rtype = 'f'
            )
            SELECT
                rp.playerId,
                rp.playerName,
                MIN(rp.rankPosition) AS highestRankPosition,
                MIN(CASE WHEN rp.dt >= :last24MonthsDate THEN rp.rankPosition ELSE NULL END) AS highestRankPosition24Months,
                MIN(CASE WHEN rp.dt >= :last12MonthsDate THEN rp.rankPosition ELSE NULL END) AS highestRankPosition12Months
            FROM ranking_positions rp
            GROUP BY rp.playerId, rp.playerName
            ORDER BY
                CASE WHEN highestRankPosition IS NULL THEN 1 ELSE 0 END,
                highestRankPosition ASC,
                CASE WHEN highestRankPosition24Months IS NULL THEN 1 ELSE 0 END,
                highestRankPosition24Months ASC,
                CASE WHEN highestRankPosition12Months IS NULL THEN 1 ELSE 0 END,
                highestRankPosition12Months ASC,
                rp.playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new HighestRankPositionRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                highestRankPosition: (int) $row['highestRankPosition'],
                highestRankPosition24Months: $row['highestRankPosition24Months'] === null ? null : (int) $row['highestRankPosition24Months'],
                highestRankPosition12Months: $row['highestRankPosition12Months'] === null ? null : (int) $row['highestRankPosition12Months'],
            );
        }

        return new HighestRankPosition($resultRows);
    }

    public function getRankingLeaders(): RankingLeaders
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH ranking_rows AS (
                SELECT
                    r.turniej AS tournamentId,
                    t.dt AS tournamentDate,
                    r.player AS playerId,
                    p.name_show AS playerName,
                    ROW_NUMBER() OVER (
                        PARTITION BY r.turniej
                        ORDER BY r.pos ASC, r.rank DESC, r.player ASC
                    ) AS rn
                FROM PFSRANKING r
                INNER JOIN PFSTOURS t ON t.id = r.turniej
                INNER JOIN PFSPLAYER p ON p.id = r.player
                WHERE r.rtype = 'f'
            ),
            leaders AS (
                SELECT
                    rr.tournamentId,
                    rr.tournamentDate,
                    rr.playerId,
                    rr.playerName
                FROM ranking_rows rr
                WHERE rr.rn = 1
            ),
            ordered_leaders AS (
                SELECT
                    l.*,
                    LAG(l.playerId) OVER (ORDER BY l.tournamentDate ASC, l.tournamentId ASC) AS previousPlayerId
                FROM leaders l
            ),
            streaks_marked AS (
                SELECT
                    ol.*,
                    SUM(
                        CASE
                            WHEN ol.previousPlayerId IS NULL OR ol.previousPlayerId <> ol.playerId THEN 1
                            ELSE 0
                        END
                    ) OVER (ORDER BY ol.tournamentDate ASC, ol.tournamentId ASC) AS streakId
                FROM ordered_leaders ol
            ),
            streaks AS (
                SELECT
                    sm.playerId,
                    sm.playerName,
                    MIN(sm.tournamentDate) AS firstTournamentDate,
                    MIN(sm.tournamentId) AS firstTournamentId,
                    MAX(sm.tournamentDate) AS lastTournamentDate,
                    MAX(sm.tournamentId) AS lastTournamentId
                FROM streaks_marked sm
                GROUP BY sm.playerId, sm.playerName, sm.streakId
            )
            SELECT
                s.playerId,
                s.playerName,
                s.firstTournamentId,
                s.lastTournamentId,
                COALESCE(tf.fullname, tf.name) AS firstTournamentName,
                COALESCE(tl.fullname, tl.name) AS lastTournamentName,
                DATEDIFF(
                    STR_TO_DATE(CAST(s.lastTournamentDate AS CHAR), '%Y%m%d'),
                    STR_TO_DATE(CAST(s.firstTournamentDate AS CHAR), '%Y%m%d')
                ) + 1 AS daysOnTop
            FROM streaks s
            INNER JOIN PFSTOURS tf ON tf.id = s.firstTournamentId
            INNER JOIN PFSTOURS tl ON tl.id = s.lastTournamentId
            ORDER BY daysOnTop DESC, s.playerName ASC, s.firstTournamentDate ASC, s.firstTournamentId ASC"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new RankingLeadersRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                daysOnTop: (int) $row['daysOnTop'],
                firstTournamentName: (string) $row['firstTournamentName'],
                lastTournamentName: (string) $row['lastTournamentName'],
            );
        }

        return new RankingLeaders($resultRows);
    }

    public function getDifferentOpponents(): DifferentOpponents
    {
        $today = new DateTimeImmutable('today');
        $last24MonthsDateInt = (int) $today->modify('-24 months')->format('Ymd');
        $last12MonthsDateInt = (int) $today->modify('-12 months')->format('Ymd');

        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
            ),
            player_opponents AS (
                SELECT ug.player1 AS player_id, ug.player2 AS opponent_id, ug.dt
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT ug.player2 AS player_id, ug.player1 AS opponent_id, ug.dt
                FROM unique_games ug
                WHERE ug.rn = 1
            )
            SELECT
                p.id AS playerId,
                p.name_show AS playerName,
                COUNT(DISTINCT po.opponent_id) AS opponentsCount,
                COUNT(DISTINCT CASE WHEN po.dt >= :last24MonthsDate THEN po.opponent_id ELSE NULL END) AS last24MonthsOpponentsCount,
                COUNT(DISTINCT CASE WHEN po.dt >= :last12MonthsDate THEN po.opponent_id ELSE NULL END) AS last12MonthsOpponentsCount
            FROM PFSPLAYER p
            LEFT JOIN player_opponents po ON po.player_id = p.id
            GROUP BY p.id, p.name_show
            HAVING COUNT(po.opponent_id) > 0
            ORDER BY last24MonthsOpponentsCount DESC, playerName ASC",
            [
                'last24MonthsDate' => $last24MonthsDateInt,
                'last12MonthsDate' => $last12MonthsDateInt,
            ]
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new DifferentOpponentsRow(
                position: $index + 1,
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                opponentsCount: (int) $row['opponentsCount'],
                last24MonthsOpponentsCount: (int) $row['last24MonthsOpponentsCount'],
                last12MonthsOpponentsCount: (int) $row['last12MonthsOpponentsCount'],
            );
        }

        return new DifferentOpponents($resultRows);
    }

    public function getMostSmallPoints(): MostSmallPoints
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
            ),
            game_sides AS (
                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player1 AS playerId,
                    ug.player2 AS opponentId,
                    ug.result1 AS points,
                    ug.result2 AS opponentPoints
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player2 AS playerId,
                    ug.player1 AS opponentId,
                    ug.result2 AS points,
                    ug.result1 AS opponentPoints
                FROM unique_games ug
                WHERE ug.rn = 1
            ),
            ranked_games AS (
                SELECT
                    gs.playerId,
                    gs.opponentId,
                    gs.points,
                    gs.opponentPoints,
                    gs.turniej,
                    gs.runda,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY gs.playerId
                        ORDER BY gs.points DESC, t.dt DESC, gs.turniej DESC, gs.runda ASC, gs.opponentId ASC
                    ) AS rn
                FROM game_sides gs
                INNER JOIN PFSTOURS t ON t.id = gs.turniej
            )
            SELECT
                rg.playerId,
                p.name_show AS playerName,
                rg.opponentId,
                op.name_show AS opponentName,
                rg.points,
                CONCAT(rg.points, ':', rg.opponentPoints) AS score,
                COALESCE(t.fullname, t.name) AS tournamentName
            FROM ranked_games rg
            INNER JOIN PFSPLAYER p ON p.id = rg.playerId
            INNER JOIN PFSPLAYER op ON op.id = rg.opponentId
            INNER JOIN PFSTOURS t ON t.id = rg.turniej
            WHERE rg.rn = 1
            ORDER BY rg.points DESC, t.dt DESC, rg.turniej DESC, rg.runda ASC, p.name_show ASC"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new MostSmallPointsRow(
                position: $index + 1,
                points: (int) $row['points'],
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                opponentId: (int) $row['opponentId'],
                opponentName: (string) $row['opponentName'],
                score: (string) $row['score'],
                tournamentName: (string) $row['tournamentName'],
            );
        }

        return new MostSmallPoints($resultRows);
    }

    public function getLeastSmallPoints(): LeastSmallPoints
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
            ),
            game_sides AS (
                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player1 AS playerId,
                    ug.player2 AS opponentId,
                    ug.result1 AS points,
                    ug.result2 AS opponentPoints
                FROM unique_games ug
                WHERE ug.rn = 1

                UNION ALL

                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player2 AS playerId,
                    ug.player1 AS opponentId,
                    ug.result2 AS points,
                    ug.result1 AS opponentPoints
                FROM unique_games ug
                WHERE ug.rn = 1
            ),
            ranked_games AS (
                SELECT
                    gs.playerId,
                    gs.opponentId,
                    gs.points,
                    gs.opponentPoints,
                    gs.turniej,
                    gs.runda,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY gs.playerId
                        ORDER BY gs.points ASC, t.dt DESC, gs.turniej DESC, gs.runda ASC, gs.opponentId ASC
                    ) AS rn
                FROM game_sides gs
                INNER JOIN PFSTOURS t ON t.id = gs.turniej
                WHERE gs.points <> 0 AND gs.points <> 1
            )
            SELECT
                rg.playerId,
                p.name_show AS playerName,
                rg.opponentId,
                op.name_show AS opponentName,
                rg.points,
                CONCAT(rg.points, ':', rg.opponentPoints) AS score,
                COALESCE(t.fullname, t.name) AS tournamentName
            FROM ranked_games rg
            INNER JOIN PFSPLAYER p ON p.id = rg.playerId
            INNER JOIN PFSPLAYER op ON op.id = rg.opponentId
            INNER JOIN PFSTOURS t ON t.id = rg.turniej
            WHERE rg.rn = 1
            ORDER BY rg.points ASC, t.dt DESC, rg.turniej DESC, rg.runda ASC, p.name_show ASC"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new LeastSmallPointsRow(
                position: $index + 1,
                points: (int) $row['points'],
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                opponentId: (int) $row['opponentId'],
                opponentName: (string) $row['opponentName'],
                score: (string) $row['score'],
                tournamentName: (string) $row['tournamentName'],
            );
        }

        return new LeastSmallPoints($resultRows);
    }

    public function getHighestPointsSum(): HighestPointsSum
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
            ),
            top_games AS (
                SELECT
                    ug.player1 AS playerId,
                    p1.name_show AS playerName,
                    ug.player2 AS opponentId,
                    p2.name_show AS opponentName,
                    (ug.result1 + ug.result2) AS points,
                    CONCAT(ug.result1, ':', ug.result2) AS score,
                    COALESCE(t.fullname, t.name) AS tournamentName,
                    t.dt AS tournamentDate,
                    ug.turniej AS tournamentId,
                    ug.runda AS roundNo
                FROM unique_games ug
                INNER JOIN PFSPLAYER p1 ON p1.id = ug.player1
                INNER JOIN PFSPLAYER p2 ON p2.id = ug.player2
                INNER JOIN PFSTOURS t ON t.id = ug.turniej
                WHERE ug.rn = 1
                ORDER BY points DESC, t.dt DESC, ug.turniej DESC, ug.runda ASC, ug.player1 ASC
                LIMIT 1000
            )
            SELECT
                tg.playerId,
                tg.playerName,
                tg.opponentId,
                tg.opponentName,
                tg.points,
                tg.score,
                tg.tournamentName
            FROM top_games tg
            ORDER BY tg.points DESC, tg.tournamentDate DESC, tg.tournamentId DESC, tg.roundNo ASC, tg.playerName ASC"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new HighestPointsSumRow(
                position: $index + 1,
                points: (int) $row['points'],
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                opponentId: (int) $row['opponentId'],
                opponentName: (string) $row['opponentName'],
                score: (string) $row['score'],
                tournamentName: (string) $row['tournamentName'],
            );
        }

        return new HighestPointsSum($resultRows);
    }

    public function getLowestPointsSum(): LowestPointsSum
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
            )
            SELECT
                ug.player1 AS playerId,
                p1.name_show AS playerName,
                ug.player2 AS opponentId,
                p2.name_show AS opponentName,
                (ug.result1 + ug.result2) AS points,
                CONCAT(ug.result1, ':', ug.result2) AS score,
                COALESCE(t.fullname, t.name) AS tournamentName
            FROM unique_games ug
            INNER JOIN PFSPLAYER p1 ON p1.id = ug.player1
            INNER JOIN PFSPLAYER p2 ON p2.id = ug.player2
            INNER JOIN PFSTOURS t ON t.id = ug.turniej
            WHERE ug.rn = 1
            ORDER BY points ASC, t.dt DESC, ug.turniej DESC, ug.runda ASC, ug.player1 ASC
            LIMIT 1000"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new LowestPointsSumRow(
                position: $index + 1,
                points: (int) $row['points'],
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                opponentId: (int) $row['opponentId'],
                opponentName: (string) $row['opponentName'],
                score: (string) $row['score'],
                tournamentName: (string) $row['tournamentName'],
            );
        }

        return new LowestPointsSum($resultRows);
    }

    public function getHighestVictory(): HighestVictory
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
            ),
            winner_games AS (
                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player1 AS playerId,
                    ug.player2 AS opponentId,
                    (ug.result1 - ug.result2) AS points,
                    CONCAT(ug.result1, ':', ug.result2) AS score
                FROM unique_games ug
                WHERE ug.rn = 1 AND ug.result1 > ug.result2

                UNION ALL

                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player2 AS playerId,
                    ug.player1 AS opponentId,
                    (ug.result2 - ug.result1) AS points,
                    CONCAT(ug.result2, ':', ug.result1) AS score
                FROM unique_games ug
                WHERE ug.rn = 1 AND ug.result2 > ug.result1
            )
            SELECT
                wg.playerId,
                p1.name_show AS playerName,
                wg.opponentId,
                p2.name_show AS opponentName,
                wg.points,
                wg.score,
                t.name AS tournamentName
            FROM winner_games wg
            INNER JOIN PFSPLAYER p1 ON p1.id = wg.playerId
            INNER JOIN PFSPLAYER p2 ON p2.id = wg.opponentId
            INNER JOIN PFSTOURS t ON t.id = wg.turniej
            ORDER BY wg.points DESC, t.dt DESC, wg.turniej DESC, wg.runda ASC, p1.name_show ASC
            LIMIT 1000"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new HighestVictoryRow(
                position: $index + 1,
                points: (int) $row['points'],
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                opponentId: (int) $row['opponentId'],
                opponentName: (string) $row['opponentName'],
                score: (string) $row['score'],
                tournamentName: (string) $row['tournamentName'],
            );
        }

        return new HighestVictory($resultRows);
    }

    public function getHighestDraw(): HighestDraw
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
            )
            SELECT
                ug.player1 AS playerId,
                p1.name_show AS playerName,
                ug.player2 AS opponentId,
                p2.name_show AS opponentName,
                ug.result1 AS points,
                CONCAT(ug.result1, ':', ug.result2) AS score,
                t.id AS tournamentId,
                t.name AS tournamentName
            FROM unique_games ug
            INNER JOIN PFSPLAYER p1 ON p1.id = ug.player1
            INNER JOIN PFSPLAYER p2 ON p2.id = ug.player2
            INNER JOIN PFSTOURS t ON t.id = ug.turniej
            WHERE ug.rn = 1 AND ug.result1 = ug.result2
            ORDER BY points DESC, t.dt DESC, ug.turniej DESC, ug.runda ASC, p1.name_show ASC
            LIMIT 1000"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new HighestDrawRow(
                position: $index + 1,
                points: (int) $row['points'],
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                opponentId: (int) $row['opponentId'],
                opponentName: (string) $row['opponentName'],
                score: (string) $row['score'],
                tournamentId: (int) $row['tournamentId'],
                tournamentName: (string) $row['tournamentName'],
            );
        }

        return new HighestDraw($resultRows);
    }

    public function getMostPointsAndLoss(): MostPointsAndLoss
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
            ),
            losing_games AS (
                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player1 AS playerId,
                    ug.player2 AS opponentId,
                    ug.result1 AS points,
                    CONCAT(ug.result1, ':', ug.result2) AS score
                FROM unique_games ug
                WHERE ug.rn = 1 AND ug.result1 < ug.result2

                UNION ALL

                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player2 AS playerId,
                    ug.player1 AS opponentId,
                    ug.result2 AS points,
                    CONCAT(ug.result2, ':', ug.result1) AS score
                FROM unique_games ug
                WHERE ug.rn = 1 AND ug.result2 < ug.result1
            )
            SELECT
                lg.playerId,
                p1.name_show AS playerName,
                lg.opponentId,
                p2.name_show AS opponentName,
                lg.points,
                lg.score,
                t.id AS tournamentId,
                t.name AS tournamentName
            FROM losing_games lg
            INNER JOIN PFSPLAYER p1 ON p1.id = lg.playerId
            INNER JOIN PFSPLAYER p2 ON p2.id = lg.opponentId
            INNER JOIN PFSTOURS t ON t.id = lg.turniej
            ORDER BY lg.points DESC, t.dt DESC, lg.turniej DESC, lg.runda ASC, p1.name_show ASC
            LIMIT 1000"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new MostPointsAndLossRow(
                position: $index + 1,
                points: (int) $row['points'],
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                opponentId: (int) $row['opponentId'],
                opponentName: (string) $row['opponentName'],
                score: (string) $row['score'],
                tournamentId: (int) $row['tournamentId'],
                tournamentName: (string) $row['tournamentName'],
            );
        }

        return new MostPointsAndLoss($resultRows);
    }

    public function getLeastPointsAndWin(): LeastPointsAndWin
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
            ),
            winning_games AS (
                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player1 AS playerId,
                    ug.player2 AS opponentId,
                    ug.result1 AS points,
                    CONCAT(ug.result1, ':', ug.result2) AS score
                FROM unique_games ug
                WHERE ug.rn = 1 AND ug.result1 > ug.result2

                UNION ALL

                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player2 AS playerId,
                    ug.player1 AS opponentId,
                    ug.result2 AS points,
                    CONCAT(ug.result2, ':', ug.result1) AS score
                FROM unique_games ug
                WHERE ug.rn = 1 AND ug.result2 > ug.result1
            )
            SELECT
                wg.playerId,
                p1.name_show AS playerName,
                wg.opponentId,
                p2.name_show AS opponentName,
                wg.points,
                wg.score,
                t.id AS tournamentId,
                t.name AS tournamentName
            FROM winning_games wg
            INNER JOIN PFSPLAYER p1 ON p1.id = wg.playerId
            INNER JOIN PFSPLAYER p2 ON p2.id = wg.opponentId
            INNER JOIN PFSTOURS t ON t.id = wg.turniej
            ORDER BY wg.points ASC, t.dt DESC, wg.turniej DESC, wg.runda ASC, p1.name_show ASC
            LIMIT 1000"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new LeastPointsAndWinRow(
                position: $index + 1,
                points: (int) $row['points'],
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                opponentId: (int) $row['opponentId'],
                opponentName: (string) $row['opponentName'],
                score: (string) $row['score'],
                tournamentId: (int) $row['tournamentId'],
                tournamentName: (string) $row['tournamentName'],
            );
        }

        return new LeastPointsAndWin($resultRows);
    }

    public function getMostOpponentPointsAndWin(): MostOpponentPointsAndWin
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
            ),
            winning_games AS (
                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player1 AS playerId,
                    ug.player2 AS opponentId,
                    ug.result2 AS points,
                    CONCAT(ug.result1, ':', ug.result2) AS score
                FROM unique_games ug
                WHERE ug.rn = 1 AND ug.result1 > ug.result2

                UNION ALL

                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player2 AS playerId,
                    ug.player1 AS opponentId,
                    ug.result1 AS points,
                    CONCAT(ug.result2, ':', ug.result1) AS score
                FROM unique_games ug
                WHERE ug.rn = 1 AND ug.result2 > ug.result1
            )
            SELECT
                wg.playerId,
                p1.name_show AS playerName,
                wg.opponentId,
                p2.name_show AS opponentName,
                wg.points,
                wg.score,
                t.id AS tournamentId,
                t.name AS tournamentName
            FROM winning_games wg
            INNER JOIN PFSPLAYER p1 ON p1.id = wg.playerId
            INNER JOIN PFSPLAYER p2 ON p2.id = wg.opponentId
            INNER JOIN PFSTOURS t ON t.id = wg.turniej
            ORDER BY wg.points DESC, t.dt DESC, wg.turniej DESC, wg.runda ASC, p1.name_show ASC
            LIMIT 1000"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new MostOpponentPointsAndWinRow(
                position: $index + 1,
                points: (int) $row['points'],
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                opponentId: (int) $row['opponentId'],
                opponentName: (string) $row['opponentName'],
                score: (string) $row['score'],
                tournamentId: (int) $row['tournamentId'],
                tournamentName: (string) $row['tournamentName'],
            );
        }

        return new MostOpponentPointsAndWin($resultRows);
    }

    public function getLeastOpponentPointsAndLoss(): LeastOpponentPointsAndLoss
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
            ),
            losing_games AS (
                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player1 AS playerId,
                    ug.player2 AS opponentId,
                    ug.result2 AS points,
                    CONCAT(ug.result1, ':', ug.result2) AS score
                FROM unique_games ug
                WHERE ug.rn = 1 AND ug.result1 < ug.result2

                UNION ALL

                SELECT
                    ug.turniej,
                    ug.runda,
                    ug.player2 AS playerId,
                    ug.player1 AS opponentId,
                    ug.result1 AS points,
                    CONCAT(ug.result2, ':', ug.result1) AS score
                FROM unique_games ug
                WHERE ug.rn = 1 AND ug.result2 < ug.result1
            )
            SELECT
                lg.playerId,
                p1.name_show AS playerName,
                lg.opponentId,
                p2.name_show AS opponentName,
                lg.points,
                lg.score,
                t.id AS tournamentId,
                t.name AS tournamentName
            FROM losing_games lg
            INNER JOIN PFSPLAYER p1 ON p1.id = lg.playerId
            INNER JOIN PFSPLAYER p2 ON p2.id = lg.opponentId
            INNER JOIN PFSTOURS t ON t.id = lg.turniej
            ORDER BY lg.points ASC, t.dt DESC, lg.turniej DESC, lg.runda ASC, p1.name_show ASC
            LIMIT 1000"
        );

        $resultRows = [];
        foreach ($rows as $index => $row) {
            $resultRows[] = new LeastOpponentPointsAndLossRow(
                position: $index + 1,
                points: (int) $row['points'],
                playerId: (int) $row['playerId'],
                playerName: (string) $row['playerName'],
                opponentId: (int) $row['opponentId'],
                opponentName: (string) $row['opponentName'],
                score: (string) $row['score'],
                tournamentId: (int) $row['tournamentId'],
                tournamentName: (string) $row['tournamentName'],
            );
        }

        return new LeastOpponentPointsAndLoss($resultRows);
    }

    private function buildSummaryRow(string $statisticName, string|int $allTimesValue, string|int $last12MonthsValue): AllTimeSummaryRow
    {
        return new AllTimeSummaryRow($statisticName, (string) $allTimesValue, (string) $last12MonthsValue);
    }

    private function formatFloat(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function formatPercent(float $value): string
    {
        return number_format($value, 2, '.', '') . '%';
    }

    /**
     * @return array<string, int|float>
     */
    private function calculateSummaryMetrics(?int $fromDate): array
    {
        $filterSql = $fromDate !== null ? ' WHERE t.dt >= :fromDate ' : '';
        $filterParams = $fromDate !== null ? ['fromDate' => $fromDate] : [];

        $tournamentSummary = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) AS tournamentsCount,
                COALESCE(SUM(t.players), 0) AS sumParticipants,
                COALESCE(AVG(t.trank), 0) AS avgTournamentRank
            FROM PFSTOURS t" . $filterSql,
            $filterParams
        );

        $activePlayers = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT tw.player)
            FROM PFSTOURWYN tw
            INNER JOIN PFSTOURS t ON t.id = tw.turniej"
            . ($fromDate !== null ? ' WHERE t.dt >= :fromDate' : ''),
            $filterParams
        );

        $latestRankingTurniej = $this->connection->fetchOne(
            "SELECT MAX(r.turniej)
            FROM PFSRANKING r
            INNER JOIN PFSTOURS t ON t.id = r.turniej
            WHERE r.rtype = 'f'"
            . ($fromDate !== null ? ' AND t.dt >= :fromDate' : ''),
            $filterParams
        );

        $rankingListedPlayers = 0;
        if ($latestRankingTurniej !== false && $latestRankingTurniej !== null) {
            $rankingListedPlayers = (int) $this->connection->fetchOne(
                "SELECT COUNT(*)
                FROM PFSRANKING
                WHERE rtype = 'f' AND turniej = :turniej",
                ['turniej' => (int) $latestRankingTurniej]
            );
        }

        $games = $this->connection->fetchAllAssociative(
            "WITH unique_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    LEAST(h.player1, h.player2) AS pmin,
                    GREATEST(h.player1, h.player2) AS pmax,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    h.host,
                    t.dt,
                    tw1.brank AS rank1,
                    tw2.brank AS rank2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
                LEFT JOIN PFSTOURWYN tw1 ON tw1.turniej = h.turniej AND tw1.player = h.player1
                LEFT JOIN PFSTOURWYN tw2 ON tw2.turniej = h.turniej AND tw2.player = h.player2"
                . ($fromDate !== null ? ' WHERE t.dt >= :fromDate' : '') .
            ")
            SELECT
                turniej, runda, player1, player2, result1, result2, host, dt, rank1, rank2
            FROM unique_games
            WHERE rn = 1",
            $filterParams
        );

        $playedGames = count($games);
        $draws = 0;
        $scoresOver350 = 0;
        $scoresOver400 = 0;
        $scoresOver500 = 0;
        $scoresOver600 = 0;
        $winnerPointsSum = 0;
        $loserPointsSum = 0;
        $decisiveGames = 0;
        $wins130PlusVs110to130 = 0;
        $wins130PlusVsBelow110 = 0;
        $wins110to130VsBelow110 = 0;
        $hostGames = 0;
        $hostWins = 0;

        foreach ($games as $game) {
            $result1 = (int) $game['result1'];
            $result2 = (int) $game['result2'];
            $rank1 = (float) ($game['rank1'] ?? 100.0);
            $rank2 = (float) ($game['rank2'] ?? 100.0);

            if ($result1 === $result2) {
                $draws++;
            } else {
                $decisiveGames++;
                if ($result1 > $result2) {
                    $winnerPointsSum += $result1;
                    $loserPointsSum += $result2;
                    $winnerRank = $rank1;
                    $loserRank = $rank2;
                } else {
                    $winnerPointsSum += $result2;
                    $loserPointsSum += $result1;
                    $winnerRank = $rank2;
                    $loserRank = $rank1;
                }

                if ($winnerRank > 130.0 && $loserRank >= 110.0 && $loserRank <= 130.0) {
                    $wins130PlusVs110to130++;
                }
                if ($winnerRank > 130.0 && $loserRank < 110.0) {
                    $wins130PlusVsBelow110++;
                }
                if ($winnerRank >= 110.0 && $winnerRank <= 130.0 && $loserRank < 110.0) {
                    $wins110to130VsBelow110++;
                }
            }

            $scores = [$result1, $result2];
            foreach ($scores as $score) {
                if ($score > 350) {
                    $scoresOver350++;
                }
                if ($score > 400) {
                    $scoresOver400++;
                }
                if ($score > 500) {
                    $scoresOver500++;
                }
                if ($score > 600) {
                    $scoresOver600++;
                }
            }

            if ($game['host'] !== null) {
                $host = (int) $game['host'];
                $hostGames++;
                if (($host === (int) $game['player1'] && $result1 > $result2) || ($host === (int) $game['player2'] && $result2 > $result1)) {
                    $hostWins++;
                }
            }
        }

        $totalScores = $playedGames > 0 ? $playedGames * 2 : 0;
        $tournamentsCount = (int) ($tournamentSummary['tournamentsCount'] ?? 0);

        return [
            'tournamentsCount' => $tournamentsCount,
            'sumParticipants' => (int) ($tournamentSummary['sumParticipants'] ?? 0),
            'avgTournamentRank' => (float) ($tournamentSummary['avgTournamentRank'] ?? 0.0),
            'activePlayers' => $activePlayers,
            'rankingListedPlayers' => $rankingListedPlayers,
            'playedGames' => $playedGames,
            'drawPercent' => $playedGames > 0 ? ($draws * 100.0 / $playedGames) : 0.0,
            'avgPlayersPerTournament' => $tournamentsCount > 0 ? ((int) ($tournamentSummary['sumParticipants'] ?? 0) / $tournamentsCount) : 0.0,
            'gamesPerPlayer' => $activePlayers > 0 ? ($playedGames / $activePlayers) : 0.0,
            'over350Percent' => $totalScores > 0 ? ($scoresOver350 * 100.0 / $totalScores) : 0.0,
            'over400Percent' => $totalScores > 0 ? ($scoresOver400 * 100.0 / $totalScores) : 0.0,
            'over500Percent' => $totalScores > 0 ? ($scoresOver500 * 100.0 / $totalScores) : 0.0,
            'over600Percent' => $totalScores > 0 ? ($scoresOver600 * 100.0 / $totalScores) : 0.0,
            'avgWinnerPoints' => $decisiveGames > 0 ? ($winnerPointsSum / $decisiveGames) : 0.0,
            'avgLoserPoints' => $decisiveGames > 0 ? ($loserPointsSum / $decisiveGames) : 0.0,
            'wins130PlusVs110to130' => $wins130PlusVs110to130,
            'wins130PlusVsBelow110' => $wins130PlusVsBelow110,
            'wins110to130VsBelow110' => $wins110to130VsBelow110,
            'hostWinPercent' => $hostGames > 0 ? ($hostWins * 100.0 / $hostGames) : 0.0,
        ];
    }
}
