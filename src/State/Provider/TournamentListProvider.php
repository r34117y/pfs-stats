<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TournamentsList\TournamentsList;
use App\ApiResource\TournamentsList\TournamentsListTournament;
use DateTime;
use Doctrine\DBAL\Connection;

class TournamentListProvider implements ProviderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TournamentsList
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
