<?php

namespace App\Service;

use App\ApiResource\PlayerRankHistory\PlayerRankHistory;
use App\ApiResource\PlayerRankHistory\PlayerRankHistoryPoint;
use App\ApiResource\PlayerRankHistory\PlayerRankMilestone;
use App\ApiResource\PlayerRankHistory\PlayerRankMilestones;
use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerRankHistoryServicePostgres implements PlayerRankHistoryServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';
    private const array EMPTY_NAME_TOURNAMENT_IDS = [
        199703150,
        199811220,
        200506191,
        200606180,
        200706100,
        200805250,
    ];
    private const array TOURNAMENT_NAME_OVERRIDES = [
        199703150 => '',
        199811220 => '',
        200506191 => '',
        200606180 => '',
        200706100 => '',
        200805250 => '',
        201204220 => "XVI Mistrzostwa Ziemi Kujawskiej w Scrabble 'O Kryształowe Jajo Świąteczne' pod ",
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function getRankHistory(int $playerId): PlayerRankHistory
    {
        $historyRows = $this->fetchHistoryRows($playerId);
        $history = [];

        foreach ($historyRows as $row) {
            if ($row['rank'] === null) {
                continue;
            }

            $history[] = new PlayerRankHistoryPoint(
                (int) $row['tournamentId'],
                (string) $row['tournamentName'],
                (string) $row['date'],
                (float) $row['rank'],
            );
        }

        return new PlayerRankHistory($history);
    }

    public function getRankMilestones(int $playerId): PlayerRankMilestones
    {
        $historyRows = $this->fetchHistoryRows($playerId);
        $rankedRows = array_values(array_filter($historyRows, static fn (array $row): bool => $row['rank'] !== null));

        if ($rankedRows === []) {
            return new PlayerRankMilestones([]);
        }

        $maxRank = 0.0;
        foreach ($rankedRows as $row) {
            $maxRank = max($maxRank, (float) $row['rank']);
        }

        $maxMilestone = max(100, ((int) floor($maxRank / 10)) * 10);
        $milestones = [];

        for ($milestone = 100; $milestone <= $maxMilestone; $milestone += 10) {
            foreach ($rankedRows as $row) {
                $rank = (float) $row['rank'];
                if ($rank < $milestone) {
                    continue;
                }

                $milestones[] = new PlayerRankMilestone(
                    $milestone,
                    (string) $row['date'],
                    (int) $row['tournamentId'],
                    (string) $row['tournamentName'],
                    $rank,
                );
                break;
            }
        }

        return new PlayerRankMilestones($milestones);
    }

    /**
     * @return array<int, array{
     *   tournamentId: int|string,
     *   tournamentName: string,
     *   date: string,
     *   rank: float|null
     * }>
     */
    private function fetchHistoryRows(int $playerId): array
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

        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                t.legacy_id AS tournament_id,
                CASE
                    WHEN t.legacy_id IN (:emptyNameTournamentIds) THEN ''
                    ELSE COALESCE(t.fullname, t.name)
                END AS tournament_name,
                t.dt,
                r.rank
            FROM tournament_result tw
            INNER JOIN tournament t
                ON t.organization_id = tw.organization_id
               AND t.legacy_id = tw.legacy_tournament_id
            LEFT JOIN ranking r
                ON r.organization_id = tw.organization_id
               AND r.legacy_player_id = tw.legacy_player_id
               AND r.legacy_tournament_id = tw.legacy_tournament_id
               AND r.rtype = 'f'
            WHERE tw.organization_id = :organizationId
              AND tw.legacy_player_id = :playerId
              AND t.legacy_id IS NOT NULL
            ORDER BY t.dt ASC, t.legacy_id ASC",
            [
                'organizationId' => $organizationId,
                'playerId' => $playerId,
                'emptyNameTournamentIds' => self::EMPTY_NAME_TOURNAMENT_IDS,
            ]
            ,
            ['emptyNameTournamentIds' => ArrayParameterType::INTEGER]
        );

        $historyRows = [];
        foreach ($rows as $row) {
            $date = DateTime::createFromFormat('Ymd', (string) $row['dt']);
            $tournamentId = (int) $row['tournament_id'];
            $historyRows[] = [
                'tournamentId' => $tournamentId,
                'tournamentName' => self::TOURNAMENT_NAME_OVERRIDES[$tournamentId] ?? (string) $row['tournament_name'],
                'date' => $date ? $date->format('Y-m-d') : (string) $row['dt'],
                'rank' => $row['rank'] !== null ? (float) $row['rank'] : null,
            ];
        }

        return $historyRows;
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
