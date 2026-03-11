<?php

namespace App\Service\Clubs;

use App\ApiResource\ClubsList\ClubsList;
use App\ApiResource\ClubsList\ClubsListClub;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ClubsService implements ClubsServiceInterface {
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    public function getClubsList(): ClubsList
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, shortname
             FROM COMMONSTATURLS
             WHERE shortname IS NOT NULL AND shortname <> '' AND shortname <> 'PFS'
             ORDER BY shortname ASC"
        );

        $clubs = [];

        foreach ($rows as $row) {
            $clubs[] = new ClubsListClub(
                id: (int) $row['id'],
                name: (string) $row['shortname'],
                city: 'Miasto: brak danych',
            );
        }

        return new ClubsList($clubs);
    }
}
