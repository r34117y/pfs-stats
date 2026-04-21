<?php

namespace App\Service\PlayerList;

use App\ApiResource\PlayersList\PlayersList;
use App\ApiResource\PlayersList\PlayersListPlayer;
use App\Repository\UserRepository;
use App\Service\PlayerPhoto\PlayerPhotoService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PlayerListService implements PlayerListServiceInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection     $connection,
        private PlayerPhotoService $playerPhotoService,
    ) {
    }

    /**
     * @throws Exception
     */
    public function getPlayers(int $organizationId): PlayersList
    {
        $sql = "SELECT id, name_show, name_alph FROM PFSPLAYER WHERE name_show <> '\"Okrutniki\" -' ORDER BY name_alph ASC";
        $result = $this->connection->executeQuery($sql);
        $rows = $result->fetchAllAssociative();
        $playerIds = [];
        foreach ($rows as $row) {
            $playerIds[] = (int) $row['id'];
        }
        $photosByPlayerId = $this->playerPhotoService->loadPhotosByPlayerId($playerIds);
        $players = [];

        foreach ($rows as $player) {
            $playerId = (int) $player['id'];
            $players[] = new PlayersListPlayer(
                $playerId,
                $player['name_show'],
                $player['name_alph'],
                null,
                $photosByPlayerId[$playerId] ?? null,
            );
        }

        return new PlayersList($players);
    }
}
