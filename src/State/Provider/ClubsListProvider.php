<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ClubsList\ClubsList;
use App\ApiResource\ClubsList\ClubsListClub;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ClubsListProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ClubsList
    {
        //$sql = "SELECT id, name_show, name_alph FROM PFSPLAYER ORDER BY name_alph ASC";
        //$result = $this->connection->executeQuery($sql);
        //$rows = $result->fetchAllAssociative();
        $clubs = [];

        /*foreach ($rows as $club) {
            $clubs[] = new ClubsListClub(
                $player['id'],
                $player['name_show'],
                $player['name_alph'],
            );
        }*/

        return new ClubsList($clubs);
    }
}
