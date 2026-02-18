<?php

namespace App\Service;

use App\ApiResource\PlayerRankHistory\PlayerRankHistory;
use App\ApiResource\PlayerRankHistory\PlayerRankHistoryPoint;
use App\ApiResource\PlayerRankHistory\PlayerRankMilestone;
use App\ApiResource\PlayerRankHistory\PlayerRankMilestones;
use DateTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerRankHistoryService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
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
        $playerExists = $this->connection->fetchOne(
            'SELECT 1 FROM PFSPLAYER WHERE id = :playerId',
            ['playerId' => $playerId]
        );

        if ($playerExists === false) {
            throw new NotFoundHttpException(sprintf('Player with id %d was not found.', $playerId));
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                t.id AS tournamentId,
                COALESCE(t.fullname, t.name) AS tournamentName,
                t.dt,
                r.rank
            FROM PFSTOURWYN tw
            INNER JOIN PFSTOURS t ON t.id = tw.turniej
            LEFT JOIN PFSRANKING r
                ON r.player = tw.player
               AND r.turniej = tw.turniej
               AND r.rtype = 'f'
            WHERE tw.player = :playerId
            ORDER BY t.dt ASC, t.id ASC",
            ['playerId' => $playerId]
        );

        $historyRows = [];
        foreach ($rows as $row) {
            $date = DateTime::createFromFormat('Ymd', (string) $row['dt']);
            $historyRows[] = [
                'tournamentId' => $row['tournamentId'],
                'tournamentName' => (string) $row['tournamentName'],
                'date' => $date ? $date->format('Y-m-d') : (string) $row['dt'],
                'rank' => $row['rank'] !== null ? (float) $row['rank'] : null,
            ];
        }

        return $historyRows;
    }
}
