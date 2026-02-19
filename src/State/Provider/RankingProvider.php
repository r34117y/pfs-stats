<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ranking\GetRanking;
use App\ApiResource\Ranking\RankingRow;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RankingProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
        private UserRepository $userRepository,
    ) {
    }
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GetRanking
    {
        $sql = "SELECT r.player, r.pos, r.rank, r.games, p.name_show, p.name_alph, p.id
                FROM PFSRANKING r
                INNER JOIN PFSPLAYER p ON r.player = p.id
                WHERE turniej = (SELECT MAX(turniej) FROM PFSRANKING)
                AND rtype='f'
                ORDER BY r.rank DESC";
        $result = $this->connection->executeQuery($sql);
        $dbRows = $result->fetchAllAssociative();
        $rankingRows = [];
        $photosByPlayerId = $this->loadPhotosByPlayerId($dbRows);

        foreach ($dbRows as $row) {
            $playerId = (int) $row['id'];
            $rankingRows[] = new RankingRow(
                (int) $row['pos'],
                (string) $row['name_show'],
                (string) $row['name_alph'],
                $playerId,
                $photosByPlayerId[$playerId] ?? null,
                (float) $row['rank'],
                (int) $row['games'],
                0.0,
                1
            );
        }

        return new GetRanking($rankingRows);
    }

    /**
     * @param list<array<string, mixed>> $dbRows
     * @return array<int, string>
     */
    private function loadPhotosByPlayerId(array $dbRows): array
    {
        $playerIds = [];
        foreach ($dbRows as $row) {
            if (isset($row['id'])) {
                $playerIds[] = (int) $row['id'];
            }
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
