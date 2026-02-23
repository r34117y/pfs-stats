<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayersList\PlayersList;
use App\ApiResource\PlayersList\PlayersListPlayer;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PlayerListProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
        private UserRepository $userRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayersList
    {
        $sql = "SELECT id, name_show, name_alph FROM PFSPLAYER ORDER BY name_alph ASC";
        $result = $this->connection->executeQuery($sql);
        $rows = $result->fetchAllAssociative();
        $photosByPlayerId = $this->loadPhotosByPlayerId($rows);
        $players = [];

        foreach ($rows as $player) {
            $playerId = (int) $player['id'];
            $players[] = new PlayersListPlayer(
                $playerId,
                $player['name_show'],
                $player['name_alph'],
                $photosByPlayerId[$playerId] ?? null,
            );
        }

        return new PlayersList($players);
    }

    /**
     * @param list<array{id: int|string, name_show: string, name_alph: string}> $playerRows
     * @return array<int, string>
     */
    private function loadPhotosByPlayerId(array $playerRows): array
    {
        $playerIds = [];
        foreach ($playerRows as $row) {
            $playerIds[] = (int) $row['id'];
        }

        $playerIds = array_values(array_unique($playerIds));
        if ($playerIds === []) {
            return [];
        }

        $photosByPlayerId = [];
        $users = $this->userRepository->findBy(['playerId' => $playerIds]);

        foreach ($users as $user) {
            $playerId = $user->getPlayerId();
            $photo = $user->getPhoto();

            if ($playerId === null || $photo === null || $photo === '') {
                continue;
            }

            if (!isset($photosByPlayerId[$playerId])) {
                $photosByPlayerId[$playerId] = $photo;
            }
        }

        return $photosByPlayerId;
    }
}
