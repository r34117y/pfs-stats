<?php

namespace App\Service\PlayerProfile;

use App\ApiResource\PlayerProfile\PlayerProfile;
use App\ApiResource\PlayerProfile\PlayerProfileTournament;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PlayerProfileServicePostgres implements PlayerProfileServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function getPlayerProfile(string $playerSlug): PlayerProfile
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            throw new NotFoundHttpException(sprintf('Player with slug %s was not found.', $playerSlug));
        }

        $player = $this->connection->fetchAssociative(
            'SELECT p.id, p.name_show, p.slug, p.city, p.bio, u.photo
            FROM player_organization po
            INNER JOIN player p ON p.id = po.player_id
            LEFT JOIN app_user u ON p.id = u.player_id
            WHERE po.organization_id = :organizationId
              AND p.slug = :slug
            LIMIT 1',
            [
                'organizationId' => $organizationId,
                'slug' => $playerSlug,
            ]
        );

        if ($player === false) {
            throw new NotFoundHttpException(sprintf('Player with slug %s was not found.', $playerSlug));
        }

        $playerId = (int) $player['id'];

        $currentRanking = $this->connection->fetchAssociative(
            "SELECT r.rank, r.position AS pos
            FROM ranking r
            WHERE r.organization_id = :organizationId
              AND r.rtype = 'f'
              AND r.player_id = :playerId
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
              AND player_id = :playerId",
            [
                'organizationId' => $organizationId,
                'playerId' => $playerId,
            ]
        );

        $firstTournament = $this->fetchPlayerTournament($organizationId, $playerId, true);
        $lastTournament = $this->fetchPlayerTournament($organizationId, $playerId, false);

        $today = new DateTimeImmutable('today');
        $last12Months = $today->modify('-12 months');
        $currentYearStart = $today->setDate((int) $today->format('Y'), 1, 1);

        $winsLast12Months = $this->fetchWinsForPeriod($organizationId, $playerId, $last12Months, $today);
        $winsCurrentYear = $this->fetchWinsForPeriod($organizationId, $playerId, $currentYearStart, $today);

        return new PlayerProfile(
            (int) $player['id'],
            (string) $player['slug'],
            (string) $player['name_show'],
            $player['city'],
            $player['bio'],
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

    /**
     * @throws Exception
     */
    private function fetchPlayerTournament(int $organizationId, int $playerId, bool $ascending): ?PlayerProfileTournament
    {
        $orderDirection = $ascending ? 'ASC' : 'DESC';
        $row = $this->connection->fetchAssociative(
            "SELECT t.id, t.name, t.dt
            FROM tournament_result tw
            INNER JOIN tournament t
                ON t.organization_id = tw.organization_id
               AND t.legacy_id = tw.legacy_tournament_id
            WHERE tw.organization_id = :organizationId
              AND tw.player_id = :playerId
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
     * @throws Exception
     */
    private function fetchWinsForPeriod(int $organizationId, int $playerId, DateTimeImmutable $from, DateTimeImmutable $to): array
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
              AND tw.player_id = :playerId
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

    /**
     * @throws Exception
     */
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
