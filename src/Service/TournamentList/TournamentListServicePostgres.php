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
                    t.name,
                    t.start_round AS start,
                    t.trank,
                    t.players_count AS players,
                    p.name_show,
                    t.winner_player_id AS winner
                FROM tournament t
                INNER JOIN player p ON p.id = t.winner_player_id
                WHERE t.organization_id = :organizationId
                ORDER BY t.id DESC";
        $result = $this->connection->executeQuery($sql, ['organizationId' => $organizationId]);
        $rows = $result->fetchAllAssociative();
        $tournaments = [];

        foreach ($rows as $row) {
            $startDate = DateTime::createFromFormat('Ymd', (string) $row['start']);
            $name = (string) ($row['name'] ?? '');

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
