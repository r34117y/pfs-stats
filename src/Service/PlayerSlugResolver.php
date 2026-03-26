<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PlayerSlugResolver
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function resolveLegacyPlayerId(string $slug): ?int
    {
        $normalizedSlug = trim($slug);
        if ($normalizedSlug === '') {
            return null;
        }

        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            return null;
        }

        $value = $this->connection->fetchOne(
            'SELECT MIN(mapped.legacy_player_id)
             FROM (
                SELECT legacy_player_id
                FROM ranking r
                INNER JOIN player p ON p.id = r.player_id
                WHERE r.organization_id = :organizationId
                  AND p.slug = :slug
                  AND r.legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player_id
                FROM tournament_result tr
                INNER JOIN player p ON p.id = tr.player_id
                WHERE tr.organization_id = :organizationId
                  AND p.slug = :slug
                  AND tr.legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player_id
                FROM play_summary ps
                INNER JOIN player p ON p.id = ps.player_id
                WHERE ps.organization_id = :organizationId
                  AND p.slug = :slug
                  AND ps.legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player1_id AS legacy_player_id
                FROM tournament_game tg
                INNER JOIN player p ON p.id = tg.player1_id
                WHERE tg.organization_id = :organizationId
                  AND p.slug = :slug
                  AND tg.legacy_player1_id IS NOT NULL
                UNION ALL
                SELECT legacy_player2_id AS legacy_player_id
                FROM tournament_game tg
                INNER JOIN player p ON p.id = tg.player2_id
                WHERE tg.organization_id = :organizationId
                  AND p.slug = :slug
                  AND tg.legacy_player2_id IS NOT NULL
             ) mapped',
            [
                'organizationId' => $organizationId,
                'slug' => $normalizedSlug,
            ],
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @throws Exception
     */
    private function fetchOrganizationId(): ?int
    {
        $value = $this->connection->fetchOne(
            'SELECT id FROM organization WHERE code = :code LIMIT 1',
            ['code' => self::ORGANIZATION_CODE],
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }
}
