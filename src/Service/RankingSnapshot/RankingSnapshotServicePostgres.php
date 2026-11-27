<?php

namespace App\Service\RankingSnapshot;

use App\Ranking\Domain\RankingType;
use App\Service\BlubryRankingGameCountService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RankingSnapshotServicePostgres implements RankingSnapshotServiceInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private ?BlubryRankingGameCountService $gameCountService = null,
    ) {
    }

    /**
     * @return list<array{playerId: int, position: int, rank: float, games: int|null, nameShow: string, nameAlph: string}>
     * @throws Exception
     */
    public function getRankingAfterTournament(int $organizationId, int $tournamentId, string $rankingType = RankingType::FUCKED): array
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
               AND r.tournament_id = :tournamentId
               AND r.rtype = :rankingType
               AND r.player_id IS NOT NULL
             ORDER BY r.rank DESC, r.position ASC, r.player_id ASC",
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
                'rankingType' => $rankingType,
            ]
        );

        if ($rows === [] && $rankingType === RankingType::FUCKED) {
            $rows = $this->connection->fetchAllAssociative(
                "WITH snapshot AS (
                    SELECT data::jsonb AS payload FROM text_resource
                    WHERE organization_id=:organizationId AND resource_type=9006
                      AND data::jsonb->>'tournamentDbId' = :tournamentIdText
                    ORDER BY id DESC LIMIT 1
                )
                SELECT r.player_id,r.position,r.rank,r.games,p.name_show,p.name_alph,p.slug,u.photo
                FROM snapshot
                CROSS JOIN LATERAL jsonb_to_recordset(snapshot.payload->'rankingRows')
                    AS r(player_id int,position int,rank double precision,games int)
                JOIN player p ON p.id=r.player_id
                LEFT JOIN app_user u ON u.player_id=p.id
                ORDER BY r.position",
                ['organizationId' => $organizationId, 'tournamentIdText' => (string) $tournamentId],
            );
        }

        $computedCounts = $rankingType === RankingType::FUCKED && $rows !== []
            ? ($this->gameCountService ?? new BlubryRankingGameCountService($this->connection))->countAfterTournament($organizationId, $tournamentId, array_column($rows, 'player_id'))
            : null;

        $ranking = [];
        foreach ($rows as $row) {
            $ranking[] = [
                'playerId' => (int) $row['player_id'],
                'position' => (int) $row['position'],
                'rank' => (float) $row['rank'],
                'games' => $computedCounts !== null ? $computedCounts[(int) $row['player_id']] : ($row['games'] === null ? null : (int) $row['games']),
                'nameShow' => (string) $row['name_show'],
                'nameAlph' => (string) $row['name_alph'],
                'photo' => $row['photo'],
                'slug' => $row['slug'],
            ];
        }

        return $ranking;
    }
}
