<?php

namespace App\Service;

use App\ApiResource\PlayerProfile\PlayerProfile;
use App\ApiResource\PlayerProfile\PlayerProfileTournament;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerProfileService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    public function getPlayerProfile(int $playerId): PlayerProfile
    {
        $player = $this->connection->fetchAssociative(
            'SELECT id, name_show FROM PFSPLAYER WHERE id = :playerId',
            ['playerId' => $playerId]
        );

        if ($player === false) {
            throw new NotFoundHttpException(sprintf('Player with id %d was not found.', $playerId));
        }

        $currentRanking = $this->connection->fetchAssociative(
            "SELECT r.rank, r.pos
            FROM PFSRANKING r
            WHERE r.rtype = 'f'
              AND r.player = :playerId
              AND r.turniej = (
                SELECT MAX(turniej)
                FROM PFSRANKING
                WHERE rtype = 'f'
              )",
            ['playerId' => $playerId]
        );

        $totals = $this->connection->fetchAssociative(
            "SELECT
                COALESCE(SUM(games), 0) AS totalGamesPlayed,
                COUNT(*) AS totalTournamentsPlayed,
                COALESCE(SUM(gwin), 0) AS totalGamesWon
            FROM PFSTOURWYN
            WHERE player = :playerId",
            ['playerId' => $playerId]
        );

        $firstTournament = $this->fetchPlayerTournament($playerId, true);
        $lastTournament = $this->fetchPlayerTournament($playerId, false);

        $today = new \DateTimeImmutable('today');
        $last12Months = $today->modify('-12 months');
        $currentYearStart = $today->setDate((int) $today->format('Y'), 1, 1);

        $winsLast12Months = $this->fetchWinsForPeriod($playerId, $last12Months, $today);
        $winsCurrentYear = $this->fetchWinsForPeriod($playerId, $currentYearStart, $today);

        return new PlayerProfile(
            (int) $player['id'],
            (string) $player['name_show'],
            null,
            null,
            $firstTournament,
            $lastTournament,
            $currentRanking !== false ? (float) $currentRanking['rank'] : null,
            $currentRanking !== false ? (int) $currentRanking['pos'] : null,
            (int) $totals['totalGamesPlayed'],
            (int) $totals['totalTournamentsPlayed'],
            (int) $totals['totalGamesWon'],
            (int) $winsLast12Months['gamesWon'],
            (int) $winsLast12Months['tournamentsWon'],
            (int) $winsCurrentYear['gamesWon'],
            (int) $winsCurrentYear['tournamentsWon'],
        );
    }

    private function fetchPlayerTournament(int $playerId, bool $ascending): ?PlayerProfileTournament
    {
        $orderDirection = $ascending ? 'ASC' : 'DESC';
        $row = $this->connection->fetchAssociative(
            "SELECT t.id, t.name, t.dt
            FROM PFSTOURWYN tw
            INNER JOIN PFSTOURS t ON t.id = tw.turniej
            WHERE tw.player = :playerId
            ORDER BY t.dt {$orderDirection}, t.id {$orderDirection}
            LIMIT 1",
            ['playerId' => $playerId]
        );

        if ($row === false) {
            return null;
        }

        return new PlayerProfileTournament(
            (int) $row['id'],
            (string) $row['name'],
            (int) $row['dt'],
        );
    }

    /**
     * @return array{gamesWon: int|string, tournamentsWon: int|string}
     */
    private function fetchWinsForPeriod(int $playerId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->connection->fetchAssociative(
            "SELECT
                COALESCE(SUM(tw.gwin), 0) AS gamesWon,
                COALESCE(SUM(CASE WHEN tw.place = 1 THEN 1 ELSE 0 END), 0) AS tournamentsWon
            FROM PFSTOURWYN tw
            INNER JOIN PFSTOURS t ON t.id = tw.turniej
            WHERE tw.player = :playerId
              AND t.dt >= :fromDate
              AND t.dt <= :toDate",
            [
                'playerId' => $playerId,
                'fromDate' => (int) $from->format('Ymd'),
                'toDate' => (int) $to->format('Ymd'),
            ]
        );
    }
}
