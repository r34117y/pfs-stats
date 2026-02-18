<?php

namespace App\Service;

use App\ApiResource\Stats\AllTimeSummary;
use App\ApiResource\Stats\AllTimeSummaryRow;
use App\ApiResource\Stats\AllTimesResults;
use App\ApiResource\Stats\AllTimesResultsPlayer;
use App\ApiResource\Stats\AvgPointsPerGame;
use App\ApiResource\Stats\AvgPointsPerGameRow;
use App\ApiResource\Stats\GamesCount;
use App\ApiResource\Stats\GamesCountRow;
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
