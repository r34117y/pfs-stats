<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ranking\GetRanking;
use App\ApiResource\Ranking\RankingRow;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RankingProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
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

        foreach ($dbRows as $row) {
            $rankingRows[] = new RankingRow(
                $row['pos'],
                $row['name_show'],
                $row['name_alph'],
                $row['id'],
                $row['rank'],
                $row['games'],
                0.0,
                1
            );
        }

        return new GetRanking($rankingRows);
    }
}
