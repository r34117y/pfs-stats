<?php

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class OldMethodCurrentRankingService
{
    private const int WINDOW_YEARS = 2;
    private const int MIN_GAMES_FOR_LIST = 30;
    private const int MAX_GAMES_INCLUDED = 200;
    private const int MIN_TOURNAMENT_RANK = 100;
    private const int NEW_METHOD_START_TOURNAMENT_ID = 202305070;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *   referenceTournamentId: int,
     *   referenceTournamentName: string,
     *   referenceDate: string,
     *   windowStartDate: string,
     *   rows: list<array{
     *      position: int,
     *      playerId: int,
     *      playerName: string,
     *      rankExact: float,
     *      rankRounded: int,
     *      games: int,
     *      tournaments: int
     *   }>
     * }
     */
    public function calculateCurrentRanking(): array
    {
        $referenceTournamentId = $this->loadLatestRankingTournamentId();
        if ($referenceTournamentId === null) {
            return [
                'referenceTournamentId' => 0,
                'referenceTournamentName' => '',
                'referenceDate' => '',
                'windowStartDate' => '',
                'rows' => [],
            ];
        }

        $referenceTournament = $this->loadTournamentById($referenceTournamentId);
        if ($referenceTournament === null) {
            return [
                'referenceTournamentId' => 0,
                'referenceTournamentName' => '',
                'referenceDate' => '',
                'windowStartDate' => '',
                'rows' => [],
            ];
        }

        $referenceDate = DateTimeImmutable::createFromFormat('Ymd', (string) $referenceTournament['dt']);
        if ($referenceDate === false) {
            return [
                'referenceTournamentId' => (int) $referenceTournament['id'],
                'referenceTournamentName' => (string) $referenceTournament['name'],
                'referenceDate' => (string) $referenceTournament['dt'],
                'windowStartDate' => (string) $referenceTournament['dt'],
                'rows' => [],
            ];
        }

        $playerNames = $this->loadPlayerNames();

        $historyByPlayer = $this->loadHistoricalTournamentResultsBeforeNewMethod();
        $careerStatsByPlayer = $this->buildCareerStatsFromHistory($historyByPlayer);

        $snapshotByPlayer = $this->loadOldMethodSnapshotBeforeNewMethod();

        $simulatedTournaments = $this->loadTournamentsForSimulation($referenceTournamentId);
        foreach ($simulatedTournaments as $tournament) {
            $tournamentId = (int) $tournament['id'];
            $tournamentDateInt = (int) $tournament['dt'];

            $participants = $this->loadTournamentParticipants($tournamentId);
            if ($participants === []) {
                $snapshotByPlayer = $this->rebuildSnapshot($historyByPlayer, $tournamentDateInt, $tournamentId);
                continue;
            }

            $preTournamentRankByPlayer = [];
            foreach ($participants as $playerId) {
                $preTournamentRankByPlayer[$playerId] = $this->calculateTournamentRankForPlayer(
                    $playerId,
                    $snapshotByPlayer,
                    $historyByPlayer,
                    $careerStatsByPlayer,
                );
            }

            $games = $this->loadUniqueTournamentGames($tournamentId);
            $tournamentPerformance = $this->calculateTournamentPerformance(
                $participants,
                $games,
                $preTournamentRankByPlayer,
            );

            foreach ($tournamentPerformance as $playerId => $performance) {
                if ($performance['games'] <= 0) {
                    continue;
                }

                $achievedRank = $performance['scalps'] / $performance['games'];

                if (!isset($historyByPlayer[$playerId])) {
                    $historyByPlayer[$playerId] = [];
                }

                $historyByPlayer[$playerId][] = [
                    'tournamentId' => $tournamentId,
                    'dateInt' => $tournamentDateInt,
                    'games' => $performance['games'],
                    'achievedRank' => $achievedRank,
                ];

                if (!isset($careerStatsByPlayer[$playerId])) {
                    $careerStatsByPlayer[$playerId] = ['games' => 0, 'scalps' => 0.0];
                }

                $careerStatsByPlayer[$playerId]['games'] += $performance['games'];
                $careerStatsByPlayer[$playerId]['scalps'] += $performance['scalps'];
            }

            $snapshotByPlayer = $this->rebuildSnapshot($historyByPlayer, $tournamentDateInt, $tournamentId);
        }

        $rows = [];
        foreach ($snapshotByPlayer as $playerId => $snapshot) {
            $playerName = $playerNames[$playerId]['name'] ?? ('Player #' . $playerId);
            $playerNameSort = $playerNames[$playerId]['nameSort'] ?? $playerName;

            $rows[] = [
                'position' => 0,
                'playerId' => $playerId,
                'playerName' => $playerName,
                'playerNameSort' => $playerNameSort,
                'rankExact' => $snapshot['rank'],
                'rankRounded' => (int) round($snapshot['rank'], 0, PHP_ROUND_HALF_UP),
                'games' => $snapshot['games'],
                'tournaments' => $snapshot['tournaments'],
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                if ($a['rankExact'] !== $b['rankExact']) {
                    return $a['rankExact'] < $b['rankExact'] ? 1 : -1;
                }

                if ($a['games'] !== $b['games']) {
                    return $a['games'] < $b['games'] ? 1 : -1;
                }

                return strcmp($a['playerNameSort'], $b['playerNameSort']);
            }
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['position'] = $index + 1;
            unset($rows[$index]['playerNameSort']);
        }

        $windowStartDate = $referenceDate->modify(sprintf('-%d years', self::WINDOW_YEARS));

        return [
            'referenceTournamentId' => (int) $referenceTournament['id'],
            'referenceTournamentName' => (string) $referenceTournament['name'],
            'referenceDate' => $referenceDate->format('Y-m-d'),
            'windowStartDate' => $windowStartDate->format('Y-m-d'),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array{name: string, nameSort: string}>
     */
    private function loadPlayerNames(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, name_show, name_alph FROM PFSPLAYER');
        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row['id']] = [
                'name' => (string) $row['name_show'],
                'nameSort' => (string) $row['name_alph'],
            ];
        }

        return $result;
    }

    private function loadLatestRankingTournamentId(): ?int
    {
        $value = $this->connection->fetchOne("SELECT MAX(turniej) FROM PFSRANKING WHERE rtype = 'f'");

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array{id: int|string, dt: int|string, name: string}|null
     */
    private function loadTournamentById(int $tournamentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id, dt, COALESCE(fullname, name) AS name
             FROM PFSTOURS
             WHERE id = :tournamentId",
            ['tournamentId' => $tournamentId]
        );

        if ($row === false) {
            return null;
        }

        return $row;
    }

    /**
     * @return array<int, list<array{tournamentId: int, dateInt: int, games: int, achievedRank: float}>>
     */
    private function loadHistoricalTournamentResultsBeforeNewMethod(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                tw.player AS playerId,
                tw.turniej AS tournamentId,
                t.dt AS tournamentDate,
                tw.games AS games,
                tw.trank AS achievedRank
            FROM PFSTOURWYN tw
            INNER JOIN PFSTOURS t ON t.id = tw.turniej
            WHERE tw.turniej < :newMethodStartTournamentId
            ORDER BY tw.player ASC, t.dt ASC, t.id ASC",
            ['newMethodStartTournamentId' => self::NEW_METHOD_START_TOURNAMENT_ID]
        );

        $historyByPlayer = [];

        foreach ($rows as $row) {
            $playerId = (int) $row['playerId'];
            $games = max(0, (int) $row['games']);
            if ($games <= 0) {
                continue;
            }

            if (!isset($historyByPlayer[$playerId])) {
                $historyByPlayer[$playerId] = [];
            }

            $historyByPlayer[$playerId][] = [
                'tournamentId' => (int) $row['tournamentId'],
                'dateInt' => (int) $row['tournamentDate'],
                'games' => $games,
                'achievedRank' => (float) $row['achievedRank'],
            ];
        }

        return $historyByPlayer;
    }

    /**
     * @param array<int, list<array{tournamentId: int, dateInt: int, games: int, achievedRank: float}>> $historyByPlayer
     * @return array<int, array{games: int, scalps: float}>
     */
    private function buildCareerStatsFromHistory(array $historyByPlayer): array
    {
        $careerStatsByPlayer = [];

        foreach ($historyByPlayer as $playerId => $history) {
            $games = 0;
            $scalps = 0.0;

            foreach ($history as $record) {
                $games += $record['games'];
                $scalps += ($record['achievedRank'] * $record['games']);
            }

            $careerStatsByPlayer[$playerId] = [
                'games' => $games,
                'scalps' => $scalps,
            ];
        }

        return $careerStatsByPlayer;
    }

    /**
     * @return array<int, array{rank: float, games: int, tournaments: int}>
     */
    private function loadOldMethodSnapshotBeforeNewMethod(): array
    {
        $tournamentId = $this->connection->fetchOne(
            "SELECT MAX(turniej)
             FROM PFSRANKING
             WHERE rtype = 'f' AND turniej < :newMethodStartTournamentId",
            ['newMethodStartTournamentId' => self::NEW_METHOD_START_TOURNAMENT_ID]
        );

        if ($tournamentId === false || $tournamentId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT player, `rank`, games
             FROM PFSRANKING
             WHERE rtype = 'f' AND turniej = :tournamentId",
            ['tournamentId' => (int) $tournamentId]
        );

        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[(int) $row['player']] = [
                'rank' => (float) $row['rank'],
                'games' => (int) $row['games'],
                'tournaments' => 0,
            ];
        }

        return $snapshot;
    }

    /**
     * @return list<array{id: int|string, dt: int|string, name: string}>
     */
    private function loadTournamentsForSimulation(int $referenceTournamentId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT id, dt, COALESCE(fullname, name) AS name
             FROM PFSTOURS
             WHERE id >= :newMethodStartTournamentId
               AND id <= :referenceTournamentId
             ORDER BY dt ASC, id ASC",
            [
                'newMethodStartTournamentId' => self::NEW_METHOD_START_TOURNAMENT_ID,
                'referenceTournamentId' => $referenceTournamentId,
            ]
        );
    }

    /**
     * @return list<int>
     */
    private function loadTournamentParticipants(int $tournamentId): array
    {
        $values = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT player FROM PFSTOURWYN WHERE turniej = :tournamentId',
            ['tournamentId' => $tournamentId]
        );

        $participants = [];
        foreach ($values as $value) {
            $participants[] = (int) $value;
        }

        return $participants;
    }

    /**
     * @return list<array{player1: int, player2: int, result1: int, result2: int}>
     */
    private function loadUniqueTournamentGames(int $tournamentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH ranked_games AS (
                SELECT
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                WHERE h.turniej = :tournamentId
            )
            SELECT
                player1,
                player2,
                result1,
                result2
            FROM ranked_games
            WHERE rn = 1",
            ['tournamentId' => $tournamentId]
        );

        $games = [];
        foreach ($rows as $row) {
            $games[] = [
                'player1' => (int) $row['player1'],
                'player2' => (int) $row['player2'],
                'result1' => (int) $row['result1'],
                'result2' => (int) $row['result2'],
            ];
        }

        return $games;
    }

    /**
     * @param array<int, array{rank: float, games: int, tournaments: int}> $snapshotByPlayer
     * @param array<int, list<array{tournamentId: int, dateInt: int, games: int, achievedRank: float}>> $historyByPlayer
     * @param array<int, array{games: int, scalps: float}> $careerStatsByPlayer
     */
    private function calculateTournamentRankForPlayer(
        int $playerId,
        array $snapshotByPlayer,
        array $historyByPlayer,
        array $careerStatsByPlayer,
    ): float {
        if (isset($snapshotByPlayer[$playerId])) {
            $rank = $snapshotByPlayer[$playerId]['rank'];

            return $rank < self::MIN_TOURNAMENT_RANK ? (float) self::MIN_TOURNAMENT_RANK : $rank;
        }

        $career = $careerStatsByPlayer[$playerId] ?? ['games' => 0, 'scalps' => 0.0];
        $careerGames = $career['games'];

        if ($careerGames >= self::MIN_GAMES_FOR_LIST) {
            return $this->calculateFallbackRankFromRecentCareerResults($playerId, $historyByPlayer);
        }

        if ($careerGames > 0) {
            $missingGames = self::MIN_GAMES_FOR_LIST - $careerGames;
            $adjustedScalps = $career['scalps'] + ($missingGames * self::MIN_TOURNAMENT_RANK);

            return $adjustedScalps / self::MIN_GAMES_FOR_LIST;
        }

        return (float) self::MIN_TOURNAMENT_RANK;
    }

    /**
     * @param array<int, list<array{tournamentId: int, dateInt: int, games: int, achievedRank: float}>> $historyByPlayer
     */
    private function calculateFallbackRankFromRecentCareerResults(int $playerId, array $historyByPlayer): float
    {
        $history = $historyByPlayer[$playerId] ?? [];
        if ($history === []) {
            return (float) self::MIN_TOURNAMENT_RANK;
        }

        $scalps = 0.0;
        $games = 0;

        for ($i = count($history) - 1; $i >= 0; $i--) {
            $record = $history[$i];
            $games += $record['games'];
            $scalps += ($record['achievedRank'] * $record['games']);

            if ($games >= self::MIN_GAMES_FOR_LIST) {
                break;
            }
        }

        if ($games <= 0) {
            return (float) self::MIN_TOURNAMENT_RANK;
        }

        return $scalps / $games;
    }

    /**
     * @param list<int> $participants
     * @param list<array{player1: int, player2: int, result1: int, result2: int}> $games
     * @param array<int, float> $preTournamentRankByPlayer
     * @return array<int, array{games: int, scalps: float}>
     */
    private function calculateTournamentPerformance(
        array $participants,
        array $games,
        array $preTournamentRankByPlayer,
    ): array {
        $performance = [];

        foreach ($participants as $playerId) {
            $performance[$playerId] = ['games' => 0, 'scalps' => 0.0];
        }

        foreach ($games as $game) {
            $player1 = $game['player1'];
            $player2 = $game['player2'];

            if (!isset($performance[$player1])) {
                $performance[$player1] = ['games' => 0, 'scalps' => 0.0];
            }
            if (!isset($performance[$player2])) {
                $performance[$player2] = ['games' => 0, 'scalps' => 0.0];
            }

            $rank1 = $preTournamentRankByPlayer[$player1] ?? (float) self::MIN_TOURNAMENT_RANK;
            $rank2 = $preTournamentRankByPlayer[$player2] ?? (float) self::MIN_TOURNAMENT_RANK;

            $result1 = $game['result1'];
            $result2 = $game['result2'];

            if ($result1 > $result2) {
                if (($rank1 - $rank2) > 50.0) {
                    $scalp1 = $rank1;
                    $scalp2 = $rank2;
                } else {
                    $scalp1 = $rank2 + 50.0;
                    $scalp2 = $rank1 - 50.0;
                }
            } elseif ($result2 > $result1) {
                if (($rank2 - $rank1) > 50.0) {
                    $scalp1 = $rank1;
                    $scalp2 = $rank2;
                } else {
                    $scalp1 = $rank2 - 50.0;
                    $scalp2 = $rank1 + 50.0;
                }
            } else {
                $scalp1 = $rank2;
                $scalp2 = $rank1;
            }

            $performance[$player1]['games']++;
            $performance[$player2]['games']++;
            $performance[$player1]['scalps'] += $scalp1;
            $performance[$player2]['scalps'] += $scalp2;
        }

        return $performance;
    }

    /**
     * @param array<int, list<array{tournamentId: int, dateInt: int, games: int, achievedRank: float}>> $historyByPlayer
     * @return array<int, array{rank: float, games: int, tournaments: int}>
     */
    private function rebuildSnapshot(array $historyByPlayer, int $currentDateInt, int $currentTournamentId): array
    {
        $currentDate = DateTimeImmutable::createFromFormat('Ymd', (string) $currentDateInt);
        if ($currentDate === false) {
            return [];
        }

        $windowStartInt = (int) $currentDate->modify(sprintf('-%d years', self::WINDOW_YEARS))->format('Ymd');

        $snapshot = [];

        foreach ($historyByPlayer as $playerId => $history) {
            $windowRecords = [];

            for ($i = count($history) - 1; $i >= 0; $i--) {
                $record = $history[$i];

                if ($record['tournamentId'] > $currentTournamentId) {
                    continue;
                }

                if ($record['dateInt'] > $currentDateInt) {
                    continue;
                }

                if ($record['dateInt'] < $windowStartInt) {
                    break;
                }

                $windowRecords[] = $record;
            }

            if ($windowRecords === []) {
                continue;
            }

            $games = 0;
            $scalps = 0.0;
            $tournaments = 0;

            foreach ($windowRecords as $record) {
                $candidateGames = $games + $record['games'];
                if ($candidateGames > self::MAX_GAMES_INCLUDED) {
                    break;
                }

                $games = $candidateGames;
                $scalps += ($record['achievedRank'] * $record['games']);
                $tournaments++;
            }

            if ($games < self::MIN_GAMES_FOR_LIST) {
                continue;
            }

            $snapshot[$playerId] = [
                'rank' => $scalps / $games,
                'games' => $games,
                'tournaments' => $tournaments,
            ];
        }

        return $snapshot;
    }
}
