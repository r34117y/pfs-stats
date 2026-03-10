<?php

namespace App\Service;

use App\ApiResource\TournamentsList\TournamentsList;
use App\ApiResource\TournamentsList\TournamentsListTournament;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TournamentListService implements TournamentListServiceInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    )
    {
    }

    /**
     * @throws Exception
     */
    public function getTournaments(): TournamentsList
    {
        $sql = "SELECT t.*, p.name_show FROM PFSTOURS t INNER JOIN PFSPLAYER p ON t.winner = p.id ORDER BY id DESC";
        $result = $this->connection->executeQuery($sql);
        $rows = $result->fetchAllAssociative();
        $tournaments = [];

        foreach ($rows as $row) {
            $startDate = DateTime::createFromFormat("Ymd", $row['start']);
            $tournaments[] = new TournamentsListTournament(
                $row['id'],
                $row['fullname'],
                $startDate ? $startDate->format('Y-m-d') : 'unknown',
                $row['trank'],
                $row['players'],
                $row['name_show'],
                $row['winner'],
            );
        }

        return new TournamentsList($tournaments);
    }
}
