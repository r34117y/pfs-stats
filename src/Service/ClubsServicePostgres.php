<?php

namespace App\Service;

use App\ApiResource\ClubsList\ClubsList;
use App\ApiResource\ClubsList\ClubsListClub;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ClubsServicePostgres implements ClubsServiceInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function getClubsList(): ClubsList
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, code
             FROM organization
             WHERE code IS NOT NULL AND code <> '' AND code <> 'PFS'
             ORDER BY code ASC"
        );

        $clubs = [];

        foreach ($rows as $row) {
            $clubs[] = new ClubsListClub(
                id: (int) $row['id'],
                name: (string) $row['code'],
                city: 'Miasto: brak danych',
            );
        }

        return new ClubsList($clubs);
    }
}
