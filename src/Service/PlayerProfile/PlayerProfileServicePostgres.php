<?php

namespace App\Service\PlayerProfile;

use App\ApiResource\PlayerProfile\PlayerProfile;
use App\ApiResource\PlayerProfile\PlayerProfileTournament;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerProfileServicePostgres implements PlayerProfileServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function getPlayerProfile(int $playerId): PlayerProfile
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            throw new NotFoundHttpException(sprintf('Player with id %d was not found.', $playerId));
        }

        $player = $this->connection->fetchAssociative(
            'WITH player_map AS (
                SELECT DISTINCT legacy_player_id, player_id
                FROM ranking
                WHERE organization_id = :organizationId
                  AND legacy_player_id IS NOT NULL
                  AND player_id IS NOT NULL
                UNION
                SELECT DISTINCT legacy_player_id, player_id
                FROM tournament_result
                WHERE organization_id = :organizationId
                  AND legacy_player_id IS NOT NULL
                  AND player_id IS NOT NULL
                UNION
                SELECT DISTINCT legacy_player_id, player_id
                FROM play_summary
                WHERE organization_id = :organizationId
                  AND legacy_player_id IS NOT NULL
                  AND player_id IS NOT NULL
            )
            SELECT pm.legacy_player_id AS id, p.name_show, u.photo
            FROM player_map pm
            INNER JOIN player p ON p.id = pm.player_id
            INNER JOIN app_user u ON p.id = u.player_id
            WHERE pm.legacy_player_id = :playerId
            LIMIT 1',
            [
                'organizationId' => $organizationId,
                'playerId' => $playerId,
            ]
        );

        if ($player === false) {
            throw new NotFoundHttpException(sprintf('Player with id %d was not found.', $playerId));
        }

        $currentRanking = $this->connection->fetchAssociative(
            "SELECT r.rank, r.position AS pos
            FROM ranking r
            WHERE r.organization_id = :organizationId
              AND r.rtype = 'f'
              AND r.legacy_player_id = :playerId
              AND r.legacy_tournament_id = (
                SELECT MAX(legacy_tournament_id)
                FROM ranking
                WHERE organization_id = :organizationId
                  AND rtype = 'f'
              )",
            [
                'organizationId' => $organizationId,
                'playerId' => $playerId,
            ]
        );

        $totals = $this->connection->fetchAssociative(
            "SELECT
                COALESCE(SUM(games), 0) AS total_games_played,
                COUNT(*) AS total_tournaments_played,
                COALESCE(SUM(gwin), 0) AS total_games_won
            FROM tournament_result
            WHERE organization_id = :organizationId
              AND legacy_player_id = :playerId",
            [
                'organizationId' => $organizationId,
                'playerId' => $playerId,
            ]
        );

        $firstTournament = $this->fetchPlayerTournament($organizationId, $playerId, true);
        $lastTournament = $this->fetchPlayerTournament($organizationId, $playerId, false);

        $today = new \DateTimeImmutable('today');
        $last12Months = $today->modify('-12 months');
        $currentYearStart = $today->setDate((int) $today->format('Y'), 1, 1);

        $winsLast12Months = $this->fetchWinsForPeriod($organizationId, $playerId, $last12Months, $today);
        $winsCurrentYear = $this->fetchWinsForPeriod($organizationId, $playerId, $currentYearStart, $today);

        return new PlayerProfile(
            (int) $player['id'],
            (string) $player['name_show'],
            null,
            $player['photo'],
            $firstTournament,
            $lastTournament,
            $currentRanking !== false ? (float) $currentRanking['rank'] : null,
            $currentRanking !== false ? (int) $currentRanking['pos'] : null,
            (int) $totals['total_games_played'],
            (int) $totals['total_tournaments_played'],
            (int) $totals['total_games_won'],
            (int) $winsLast12Months['games_won'],
            (int) $winsLast12Months['tournaments_won'],
            (int) $winsCurrentYear['games_won'],
            (int) $winsCurrentYear['tournaments_won'],
        );
    }

    private function fetchPlayerTournament(int $organizationId, int $playerId, bool $ascending): ?PlayerProfileTournament
    {
        $orderDirection = $ascending ? 'ASC' : 'DESC';
        $row = $this->connection->fetchAssociative(
            "SELECT t.legacy_id AS id, t.name, t.dt
            FROM tournament_result tw
            INNER JOIN tournament t
                ON t.organization_id = tw.organization_id
               AND t.legacy_id = tw.legacy_tournament_id
            WHERE tw.organization_id = :organizationId
              AND tw.legacy_player_id = :playerId
              AND t.legacy_id IS NOT NULL
            ORDER BY t.dt {$orderDirection}, t.legacy_id {$orderDirection}
            LIMIT 1",
            [
                'organizationId' => $organizationId,
                'playerId' => $playerId,
            ]
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
     * @return array{games_won: int|string, tournaments_won: int|string}
     */
    private function fetchWinsForPeriod(int $organizationId, int $playerId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->connection->fetchAssociative(
            "SELECT
                COALESCE(SUM(tw.gwin), 0) AS games_won,
                COALESCE(SUM(CASE WHEN tw.place = 1 THEN 1 ELSE 0 END), 0) AS tournaments_won
            FROM tournament_result tw
            INNER JOIN tournament t
                ON t.organization_id = tw.organization_id
               AND t.legacy_id = tw.legacy_tournament_id
            WHERE tw.organization_id = :organizationId
              AND tw.legacy_player_id = :playerId
              AND t.dt >= :fromDate
              AND t.dt <= :toDate",
            [
                'organizationId' => $organizationId,
                'playerId' => $playerId,
                'fromDate' => (int) $from->format('Ymd'),
                'toDate' => (int) $to->format('Ymd'),
            ]
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
