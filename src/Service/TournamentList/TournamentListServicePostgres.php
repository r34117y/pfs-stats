<?php

namespace App\Service\TournamentList;

use App\ApiResource\TournamentsList\TournamentsList;
use App\ApiResource\TournamentsList\TournamentsListTournament;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TournamentListServicePostgres implements TournamentListServiceInterface
{
    private const int TOURNAMENT_ID_WITH_TRAILING_SPACE = 201204220;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function getTournaments(int $organizationId): TournamentsList
    {
        $sql = "SELECT
                    t.id,
                    t.legacy_id,
                    t.fullname,
                    t.start_round AS start,
                    t.trank,
                    t.players_count AS players,
                    p.name_show,
                    t.legacy_winner_player_id AS winner
                FROM tournament t
                INNER JOIN player p ON p.id = t.winner_player_id
                WHERE t.organization_id = :organizationId
                  AND t.legacy_id IS NOT NULL
                  AND t.legacy_winner_player_id IS NOT NULL
                ORDER BY t.legacy_id DESC";
        $result = $this->connection->executeQuery($sql, ['organizationId' => $organizationId]);
        $rows = $result->fetchAllAssociative();
        $tournaments = [];

        foreach ($rows as $row) {
            $startDate = DateTime::createFromFormat('Ymd', (string) $row['start']);
            $name = (string) ($row['fullname'] ?? '');
            if ((int) $row['legacy_id'] === self::TOURNAMENT_ID_WITH_TRAILING_SPACE && !str_ends_with($name, ' ')) {
                $name .= ' ';
            }

            $tournaments[] = new TournamentsListTournament(
                (int) $row['id'],
                $name,
                $startDate ? $startDate->format('Y-m-d') : 'unknown',
                (float) $row['trank'],
                (int) $row['players'],
                (string) $row['name_show'],
                (int) $row['winner'],
            );
        }

        return new TournamentsList($tournaments);
    }
}
