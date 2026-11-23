<?php

namespace App\Service\OldMethodCurrentRanking;

use App\Ranking\Domain\RankingType;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class OldMethodCurrentRankingServicePostgres implements OldMethodCurrentRankingServiceInterface {
    private const int WINDOW_DAYS = 730;
    private const int MIN_GAMES_FOR_LIST = 30;
    private const int MAX_GAMES_INCLUDED = 200;
    private const int MIN_TOURNAMENT_RANK = 100;
    private const int NEW_METHOD_PFS_ID = 1271;
    private const string OLD_METHOD_RANKING_TYPE = RankingType::NORMAL;
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
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
     *      playerNameAlph: string,
     *      rankExact: float,
     *      rankRounded: int,
     *      games: int,
     *      tournaments: int
     *   }>
     * }
     */
    public function calculateCurrentRanking(): array
    {
        $organizationId = $this->loadOrganizationId();
        if ($organizationId === null) {
            return [
                'referenceTournamentId' => 0,
                'referenceTournamentName' => '',
                'referenceDate' => '',
                'windowStartDate' => '',
                'rows' => [],
            ];
        }

        $referenceTournamentId = $this->loadLatestStoredOldMethodRankingTournamentId($organizationId);
        if ($referenceTournamentId === null) {
            return [
                'referenceTournamentId' => 0,
                'referenceTournamentName' => '',
                'referenceDate' => '',
                'windowStartDate' => '',
                'rows' => [],
            ];
        }

        $referenceTournament = $this->loadTournamentMeta($organizationId, $referenceTournamentId);
        $referenceDate = $referenceTournament['dateInt'] !== null
            ? DateTimeImmutable::createFromFormat('Ymd', (string) $referenceTournament['dateInt'])
            : false;

        return [
            'referenceTournamentId' => $referenceTournamentId,
            'referenceTournamentName' => $referenceTournament['name'] ?? '',
            'referenceDate' => $referenceDate !== false ? $referenceDate->format('Y-m-d') : '',
            'windowStartDate' => $referenceDate !== false
                ? $referenceDate->modify(sprintf('-%d days', self::WINDOW_DAYS))->format('Y-m-d')
                : '',
            'rows' => $this->loadStoredOldMethodRankingRows($organizationId, $referenceTournamentId),
        ];
    }

    public function calculateRankingSnapshots(): iterable
    {
        $organizationId = $this->loadOrganizationId();
        if ($organizationId === null) {
            return;
        }

        $referenceTournamentId = $this->loadLatestRankingTournamentId($organizationId);
        if ($referenceTournamentId === null) {
            return;
        }

        yield from $this->calculateRankingSnapshotsUntilTournament($organizationId, $referenceTournamentId);
    }

    public function calculateRankingSnapshotAfterTournament(int $organizationId, int $tournamentId): ?array
    {
        $snapshotAfterTournament = null;
        foreach ($this->calculateRankingSnapshotsUntilTournament($organizationId, $tournamentId, true) as $snapshot) {
            if ((int) $snapshot['referenceTournamentId'] === $tournamentId) {
                $snapshotAfterTournament = $snapshot;
            }
        }

        return $snapshotAfterTournament;
    }

    private function calculateRankingSnapshotsUntilTournament(int $organizationId, int $referenceTournamentId, bool $targetOnly = false): iterable
    {
        $playerNames = $this->loadPlayerNames($organizationId);
        $historyByPlayer = $this->loadHistoricalTournamentResultsBeforeNewMethod($organizationId);
        $careerStatsByPlayer = $this->buildCareerStatsFromHistory($historyByPlayer);
        $snapshotByPlayer = $this->loadOldMethodSnapshotBeforeNewMethod($organizationId);

        $tournaments = $this->loadTournamentsForSimulation($organizationId, $referenceTournamentId);
        [$participantsByTournament, $gamesByTournament] = $this->loadSimulationData($organizationId, array_column($tournaments, 'id'));
        foreach ($tournaments as $tournament) {
            $tournamentId = (int) $tournament['id'];
            $tournamentDateInt = (int) $tournament['dt'];

            $participants = $participantsByTournament[$tournamentId] ?? [];
            if ($participants !== []) {
                $preTournamentRankByPlayer = [];
                foreach ($participants as $playerId) {
                    $preTournamentRankByPlayer[$playerId] = $this->calculateTournamentRankForPlayer(
                        $playerId,
                        $snapshotByPlayer,
                        $historyByPlayer,
                        $careerStatsByPlayer,
                    );
                }

                $games = $gamesByTournament[$tournamentId] ?? [];
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
            }

            $snapshot = $this->rebuildSnapshot($historyByPlayer, $tournamentDateInt, $tournamentId);
            $snapshotByPlayer = $snapshot['main'];
            if ($targetOnly && $tournamentId !== $referenceTournamentId) {
                continue;
            }
            $referenceDate = DateTimeImmutable::createFromFormat('Ymd', (string) $tournamentDateInt);
            if ($referenceDate === false) {
                continue;
            }

            yield [
                'organizationId' => $organizationId,
                'referenceTournamentId' => $tournamentId,
                'referenceTournamentName' => (string) $tournament['name'],
                'referenceDate' => $referenceDate->format('Y-m-d'),
                'windowStartDate' => $referenceDate->modify(sprintf('-%d days', self::WINDOW_DAYS))->format('Y-m-d'),
                'rows' => $this->buildRankingRows($snapshotByPlayer, $playerNames),
                'waitingRows' => $this->buildRankingRows($snapshot['waiting'], $playerNames),
            ];
        }
    }

    /**
     * @param array<int, array{rank: float, games: int, tournaments: int}> $snapshotByPlayer
     * @param array<int, array{name: string, nameSort: string, photo: mixed, slug: mixed}> $playerNames
     * @return list<array<string, mixed>>
     */
    private function buildRankingRows(array $snapshotByPlayer, array $playerNames): array
    {
        $rows = [];
        foreach ($snapshotByPlayer as $playerId => $snapshot) {
            $playerName = $playerNames[$playerId]['name'] ?? ('Player #' . $playerId);
            $playerNameSort = $playerNames[$playerId]['nameSort'] ?? $playerName;

            $rows[] = [
                'position' => 0,
                'playerId' => $playerId,
                'playerName' => $playerName,
                'playerNameAlph' => $playerNameSort,
                'playerNameSort' => $playerNameSort,
                'rankExact' => $snapshot['rank'],
                'rankRounded' => (int) round($snapshot['rank'], 0, PHP_ROUND_HALF_UP),
                'games' => $snapshot['games'],
                'tournaments' => $snapshot['tournaments'],
                'photo' => $playerNames[$playerId]['photo'],
                'slug' => $playerNames[$playerId]['slug'],
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

        return $rows;
    }

    private function loadOrganizationId(): ?int
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

    private function loadLatestStoredOldMethodRankingTournamentId(int $organizationId): ?int
    {
        $value = $this->connection->fetchOne(
            'SELECT r.tournament_id FROM ranking r JOIN tournament t ON t.id=r.tournament_id AND t.organization_id=r.organization_id
             WHERE r.organization_id = :organizationId AND r.rtype = :rankingType
             ORDER BY t.dt DESC,t.id DESC LIMIT 1',
            [
                'organizationId' => $organizationId,
                'rankingType' => self::OLD_METHOD_RANKING_TYPE,
            ],
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array{dateInt:int|null, name:string}
     */
    private function loadTournamentMeta(int $organizationId, int $tournamentId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT dt, COALESCE(fullname, name) AS name
             FROM tournament
             WHERE organization_id = :organizationId
               AND id = :tournamentId
             LIMIT 1',
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
            ],
        );

        if ($row === false) {
            return ['dateInt' => null, 'name' => ''];
        }

        return [
            'dateInt' => $row['dt'] !== null ? (int) $row['dt'] : null,
            'name' => is_string($row['name'] ?? null) ? (string) $row['name'] : '',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadStoredOldMethodRankingRows(int $organizationId, int $tournamentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                r.position,
                r.player_id,
                r.rank,
                r.games,
                p.name_show,
                p.name_alph,
                p.slug,
                u.photo
             FROM ranking r
             INNER JOIN player p ON p.id = r.player_id
             LEFT JOIN app_user u ON u.player_id = p.id
             WHERE r.organization_id = :organizationId
               AND r.tournament_id = :tournamentId
               AND r.rtype = :rankingType
               AND r.player_id IS NOT NULL
             ORDER BY r.position ASC, r.player_id ASC',
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
                'rankingType' => self::OLD_METHOD_RANKING_TYPE,
            ],
        );

        $rankingRows = [];
        foreach ($rows as $row) {
            $rankingRows[] = [
                'position' => (int) $row['position'],
                'playerId' => (int) $row['player_id'],
                'playerName' => (string) $row['name_show'],
                'playerNameAlph' => (string) $row['name_alph'],
                'rankExact' => (float) $row['rank'],
                'rankRounded' => (int) round((float) $row['rank'], 0, PHP_ROUND_HALF_UP),
                'games' => (int) $row['games'],
                'tournaments' => 0,
                'photo' => $row['photo'],
                'slug' => $row['slug'],
            ];
        }

        return $rankingRows;
    }

    /**
     * @return array<int, array{name: string, nameSort: string}>
     */
    private function loadPlayerNames(int $organizationId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT
                x.player_id AS player_id,
                p.name_show,
                p.name_alph,
                p.slug,
                u.photo,
                p.slug
             FROM (
                SELECT player_id
                FROM ranking
                WHERE organization_id = :organizationId
                  AND player_id IS NOT NULL
                UNION
                SELECT player_id
                FROM tournament_result
                WHERE organization_id = :organizationId
                  AND player_id IS NOT NULL
             ) x
             INNER JOIN player p ON p.id = x.player_id
             LEFT JOIN app_user u ON p.id = u.player_id",
            ['organizationId' => $organizationId]
        );
        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row['player_id']] = [
                'name' => (string) $row['name_show'],
                'nameSort' => (string) $row['name_alph'],
                'photo' => $row['photo'],
                'slug' => $row['slug']
            ];
        }

        return $result;
    }

    private function loadLatestRankingTournamentId(int $organizationId): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT r.tournament_id FROM ranking r JOIN tournament t ON t.id=r.tournament_id AND t.organization_id=r.organization_id
             WHERE r.organization_id = :organizationId AND r.rtype = :rtype
             ORDER BY t.dt DESC,t.id DESC LIMIT 1",
            [
                'organizationId' => $organizationId,
                'rtype' => RankingType::FUCKED
            ]
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<int, list<array{tournamentId: int, dateInt: int, games: int, achievedRank: float}>>
     */
    private function loadHistoricalTournamentResultsBeforeNewMethod(int $organizationId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                tw.player_id AS player_id,
                tw.tournament_id AS tournament_id,
                t.dt AS tournament_date,
                tw.games AS games,
                tw.trank AS achieved_rank
            FROM tournament_result tw
            INNER JOIN tournament t
                ON t.organization_id = tw.organization_id
               AND t.id = tw.tournament_id
            WHERE tw.organization_id = :organizationId
              AND tw.player_id IS NOT NULL
              AND tw.tournament_id IS NOT NULL
              AND (t.dt,t.id) < (SELECT dt,id FROM tournament WHERE id = :newMethodStartTournamentId)
            ORDER BY tw.player_id ASC, t.dt ASC, t.id ASC",
            [
                'organizationId' => $organizationId,
                'newMethodStartTournamentId' => $this->loadMethodSplitId($organizationId),
            ]
        );

        $historyByPlayer = [];

        foreach ($rows as $row) {
            $playerId = (int) $row['player_id'];
            $games = max(0, (int) $row['games']);
            if ($games <= 0) {
                continue;
            }

            if (!isset($historyByPlayer[$playerId])) {
                $historyByPlayer[$playerId] = [];
            }

            $historyByPlayer[$playerId][] = [
                'tournamentId' => (int) $row['tournament_id'],
                'dateInt' => (int) $row['tournament_date'],
                'games' => $games,
                'achievedRank' => (float) $row['achieved_rank'],
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
    private function loadOldMethodSnapshotBeforeNewMethod(int $organizationId): array
    {
        $tournamentId = $this->connection->fetchOne(
            "SELECT r.tournament_id
             FROM ranking r JOIN tournament t ON t.id = r.tournament_id AND t.organization_id = r.organization_id
             WHERE r.organization_id = :organizationId
               AND r.rtype = :rtype
               AND (t.dt,t.id) < (SELECT dt,id FROM tournament WHERE id = :newMethodStartTournamentId)
             ORDER BY t.dt DESC,t.id DESC LIMIT 1",
            [
                'organizationId' => $organizationId,
                'newMethodStartTournamentId' => $this->loadMethodSplitId($organizationId),
                'rtype' => RankingType::FUCKED
            ]
        );

        if ($tournamentId === false || $tournamentId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT player_id AS player, rank, games
             FROM ranking
             WHERE organization_id = :organizationId
               AND rtype = :rtype
               AND tournament_id = :tournamentId
               AND player_id IS NOT NULL",
            [
                'organizationId' => $organizationId,
                'tournamentId' => (int) $tournamentId,
                'rtype' => RankingType::FUCKED
            ]
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
    private function loadTournamentsForSimulation(int $organizationId, int $referenceTournamentId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT id, dt, COALESCE(fullname, name) AS name
             FROM tournament
             WHERE organization_id = :organizationId
               AND (dt,id) >= (SELECT dt,id FROM tournament WHERE id = :newMethodStartTournamentId)
               AND (dt,id) <= (SELECT dt,id FROM tournament WHERE id = :referenceTournamentId)
             ORDER BY dt ASC, id ASC",
            [
                'organizationId' => $organizationId,
                'newMethodStartTournamentId' => $this->loadMethodSplitId($organizationId),
                'referenceTournamentId' => $referenceTournamentId,
            ]
        );
    }

    /**
     * @return list<int>
     */
    private function loadMethodSplitId(int $organizationId): int
    {
        $rows = $this->connection->fetchFirstColumn("SELECT id FROM tournament WHERE organization_id = ? AND (urlid = ? OR fullname = 'II Wielicki Turniej w Scrabble') ORDER BY dt,id", [$organizationId, self::NEW_METHOD_PFS_ID]);
        if (count($rows) !== 1) {
            throw new \RuntimeException('Missing or ambiguous old-ranking method split event (PFS 1271).');
        }
        return (int) $rows[0];
    }

    /** @return array{array<int,list<int>>,array<int,list<array<string,int>>>} */
    private function loadSimulationData(int $org, array $ids): array
    {
        $participants = []; $games = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $params = ['org' => $org, 'ids' => array_map('intval', $chunk)];
            $types = ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER];
            foreach ($this->connection->fetchAllAssociative('SELECT DISTINCT tournament_id,player_id FROM tournament_result WHERE organization_id=:org AND tournament_id IN (:ids) AND player_id IS NOT NULL', $params, $types) as $r) {
                $participants[(int) $r['tournament_id']][] = (int) $r['player_id'];
            }
            $rows = $this->connection->fetchAllAssociative('SELECT DISTINCT ON (tournament_id,round_no,table_no,LEAST(player1_id,player2_id),GREATEST(player1_id,player2_id)) tournament_id,player1_id AS player1,player2_id AS player2,result1,result2 FROM tournament_game WHERE organization_id=:org AND tournament_id IN (:ids) AND player1_id IS NOT NULL AND player2_id IS NOT NULL AND ((result1>0 AND result2>0) OR ranko IS NOT NULL) AND NOT ((result1=400 AND result2=0) OR (result1=0 AND result2=400)) ORDER BY tournament_id,round_no,table_no,LEAST(player1_id,player2_id),GREATEST(player1_id,player2_id),player1_id', $params, $types);
            foreach ($rows as $r) {
                $tid = (int) $r['tournament_id']; unset($r['tournament_id']);
                $games[$tid][] = array_map('intval', $r);
            }
        }
        return [$participants, $games];
    }

    private function loadTournamentParticipants(int $organizationId, int $tournamentId): array
    {
        $values = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT player_id
             FROM tournament_result
             WHERE organization_id = :organizationId
               AND tournament_id = :tournamentId
               AND player_id IS NOT NULL',
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
            ]
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
    private function loadUniqueTournamentGames(int $organizationId, int $tournamentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH ranked_games AS (
                SELECT
                    h.player1_id AS player1,
                    h.player2_id AS player2,
                    h.result1,
                    h.result2,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.round_no, LEAST(h.player1_id, h.player2_id), GREATEST(h.player1_id, h.player2_id)
                        ORDER BY h.player1_id ASC
                    ) AS rn
                FROM tournament_game h
                WHERE h.organization_id = :organizationId
                  AND h.tournament_id = :tournamentId
                  AND h.player1_id IS NOT NULL
                  AND h.player2_id IS NOT NULL
                  AND NOT ((h.result1=400 AND h.result2=0) OR (h.result1=0 AND h.result2=400))
            )
            SELECT
                player1,
                player2,
                result1,
                result2
            FROM ranked_games
            WHERE rn = 1",
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
            ]
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
        } else {
            $career = $careerStatsByPlayer[$playerId] ?? ['games' => 0, 'scalps' => 0.0];
            $careerGames = $career['games'];

            if ($careerGames >= self::MIN_GAMES_FOR_LIST) {
                $rank = $this->calculateFallbackRankFromRecentCareerResults($playerId, $historyByPlayer);
            } else {
                $missingGames = self::MIN_GAMES_FOR_LIST - $careerGames;
                $rank = ($career['scalps'] + $missingGames * self::MIN_TOURNAMENT_RANK) / self::MIN_GAMES_FOR_LIST;
            }
        }

        // Only the tournament starting rank is rounded; list averages stay exact for ordering.
        return round(max((float) self::MIN_TOURNAMENT_RANK, $rank), 0, PHP_ROUND_HALF_UP);
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
     * @return array{
     *   main: array<int, array{rank: float, games: int, tournaments: int}>,
     *   waiting: array<int, array{rank: float, games: int, tournaments: int}>
     * }
     */
    private function rebuildSnapshot(array $historyByPlayer, int $currentDateInt, int $currentTournamentId): array
    {
        $currentDate = DateTimeImmutable::createFromFormat('Ymd', (string) $currentDateInt);
        if ($currentDate === false) {
            return ['main' => [], 'waiting' => []];
        }

        $windowStartInt = (int) $currentDate->modify(sprintf('-%d days', self::WINDOW_DAYS))->format('Ymd');

        $mainSnapshot = [];
        $waitingSnapshot = [];

        foreach ($historyByPlayer as $playerId => $history) {
            $windowRecords = [];

            for ($i = count($history) - 1; $i >= 0; $i--) {
                $record = $history[$i];

                if ($record['dateInt'] === $currentDateInt && $record['tournamentId'] > $currentTournamentId) {
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

            if ($games === 0) {
                continue;
            }
            if ($games < self::MIN_GAMES_FOR_LIST) {
                $waitingSnapshot[$playerId] = [
                    'rank' => $scalps / $games,
                    'games' => $games,
                    'tournaments' => $tournaments,
                ];
                continue;
            }

            $mainSnapshot[$playerId] = [
                'rank' => $scalps / $games,
                'games' => $games,
                'tournaments' => $tournaments,
            ];
        }

        return ['main' => $mainSnapshot, 'waiting' => $waitingSnapshot];
    }
}
