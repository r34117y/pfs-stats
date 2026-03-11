<?php

namespace App\Service\RankingSnapshot;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RankingSnapshotServicePostgres implements RankingSnapshotServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{playerId: int, position: int, rank: float, games: int, nameShow: string, nameAlph: string}>
     */
    public function getRankingAfterTournament(int $tournamentId): array
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                r.legacy_player_id AS player_id,
                r.position,
                r.rank,
                r.games,
                p.name_show,
                p.name_alph
             FROM ranking r
             INNER JOIN player p ON r.player_id = p.id
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
            ];
        }

        return $ranking;
    }

    private function fetchOrganizationId(): ?int
    {
        $value = $this->connection->fetchOne(
            'SELECT id FROM organization WHERE code = :code LIMIT 1',
            ['code' => self::ORGANIZATION_CODE]
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }
}
