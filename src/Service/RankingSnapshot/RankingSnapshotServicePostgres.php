<?php

namespace App\Service\RankingSnapshot;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RankingSnapshotServicePostgres implements RankingSnapshotServiceInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{playerId: int, position: int, rank: float, games: int, nameShow: string, nameAlph: string}>
     * @throws Exception
     */
    public function getRankingAfterTournament(int $organizationId, int $tournamentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                r.player_id,
                r.position,
                r.rank,
                r.games,
                p.name_show,
                p.name_alph,
                p.slug,
                u.photo
             FROM ranking r
             INNER JOIN player p ON r.player_id = p.id
             LEFT JOIN app_user u ON u.player_id = p.id
             WHERE r.organization_id = :organizationId
               AND r.legacy_tournament_id = :tournamentId
               AND r.rtype = 'f'
               AND r.legacy_player_id IS NOT NULL
             ORDER BY r.rank DESC, r.position ASC, r.legacy_player_id ASC",
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
            ]
        );

        $ranking = [];
        foreach ($rows as $row) {
            $ranking[] = [
                'playerId' => (int) $row['player_id'],
                'position' => (int) $row['position'],
                'rank' => (float) $row['rank'],
                'games' => (int) $row['games'],
                'nameShow' => (string) $row['name_show'],
                'nameAlph' => (string) $row['name_alph'],
                'photo' => $row['photo'],
                'slug' => $row['slug'],
            ];
        }

        return $ranking;
    }
}
