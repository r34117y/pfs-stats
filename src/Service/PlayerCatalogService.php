<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PlayerCatalogService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private PfsNameNormalizer $nameNormalizer,
    )
    {
    }

    /**
     * @return list<array<string, mixed>>
     * @throws Exception
     */
    public function loadPlayerCatalog(int $organizationId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                p.id AS player_id,
                p.name_show,
                p.name_alph
            FROM player_organization po
            INNER JOIN player p ON p.id = po.player_id
            WHERE po.organization_id = :organizationId
              AND p.name_show IS NOT NULL
            ORDER BY p.id ASC",
            [
                'organizationId' => $organizationId,
            ],
        );

        return array_map(function (array $row): array {
            return [
                'playerId' => (int) $row['player_id'],
                'nameShow' => (string) $row['name_show'],
                'nameAlph' => (string) ($row['name_alph'] ?? ''),
                'normalized' => $this->nameNormalizer->normalizeForMatch((string) $row['name_show'])
            ];
        }, $rows);
    }
}
