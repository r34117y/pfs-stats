<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RankingSnapshotService implements RankingSnapshotServiceInterface {
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{playerId: int, position: int, rank: float, games: int, nameShow: string, nameAlph: string}>
     */
    public function getRankingAfterTournament(int $tournamentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT r.player, r.pos, r.rank, r.games, p.name_show, p.name_alph
             FROM PFSRANKING r
             INNER JOIN PFSPLAYER p ON r.player = p.id
             WHERE r.turniej = :tournamentId
             AND r.rtype = 'f'
             ORDER BY r.rank DESC",
            ['tournamentId' => $tournamentId]
        );

        $ranking = [];
        foreach ($rows as $row) {
            $ranking[] = [
                'playerId' => (int) $row['player'],
                'position' => (int) $row['pos'],
                'rank' => (float) $row['rank'],
                'games' => (int) $row['games'],
                'nameShow' => (string) $row['name_show'],
                'nameAlph' => (string) $row['name_alph'],
            ];
        }

        return $ranking;
    }
}
