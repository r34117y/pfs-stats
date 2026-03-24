<?php

namespace App\Service\PlayerPhoto;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PlayerPhotoService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {}

    /**
     * @throws Exception
     */
    public function loadPhotosByPlayerId(array $playerIds): array
    {
        $playerIds = array_values(array_unique($playerIds));
        if ($playerIds === []) {
            return [];
        }

        $photosByPlayerId = [];
        $rows = $this->connection->fetchAllAssociative(
            "SELECT player_id, photo
             FROM app_user
             WHERE player_id IN (:playerIds)
               AND photo IS NOT NULL
               AND photo <> ''",
            ['playerIds' => $playerIds],
            ['playerIds' => ArrayParameterType::INTEGER]
        );

        foreach ($rows as $row) {
            $photosByPlayerId[(int) $row['player_id']] = (string) $row['photo'];
        }

        return $photosByPlayerId;
    }
}
