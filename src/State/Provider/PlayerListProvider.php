<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayersList\PlayersList;
use App\ApiResource\PlayersList\PlayersListPlayer;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PlayerListProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayersList
    {
        $sql = "SELECT id, name_show, name_alph FROM PFSPLAYER ORDER BY name_alph ASC";
        $result = $this->connection->executeQuery($sql);
        $rows = $result->fetchAllAssociative();
        $players = [];

        foreach ($rows as $player) {
            $players[] = new PlayersListPlayer(
                $player['id'],
                $player['name_show'],
                $player['name_alph'],
            );
        }

        return new PlayersList($players);
    }
}
