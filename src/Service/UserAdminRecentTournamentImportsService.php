<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\UserAdmin\UserAdminRecentTournamentImport;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class UserAdminRecentTournamentImportsService
{
    private const int DEFAULT_LIMIT_PER_ORGANIZATION = 5;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @param list<int> $organizationIds
     * @return list<UserAdminRecentTournamentImport>
     * @throws Exception
     */
    public function getRecentImportsForOrganizations(array $organizationIds, int $limitPerOrganization = self::DEFAULT_LIMIT_PER_ORGANIZATION): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'WITH ranked_tournaments AS (
                SELECT
                    t.organization_id,
                    o.name AS organization_name,
                    t.legacy_id AS tournament_id,
                    COALESCE(t.fullname, t.name, \'\') AS tournament_name,
                    t.dt,
                    t.urlid,
                    ROW_NUMBER() OVER (
                        PARTITION BY t.organization_id
                        ORDER BY t.dt DESC, t.legacy_id DESC NULLS LAST, t.id DESC
                    ) AS row_no
                FROM tournament t
                INNER JOIN organization o ON o.id = t.organization_id
                WHERE t.organization_id IN (:organizationIds)
                  AND t.legacy_id IS NOT NULL
            )
            SELECT
                organization_id,
                organization_name,
                tournament_id,
                tournament_name,
                dt,
                urlid
            FROM ranked_tournaments
            WHERE row_no <= :limitPerOrganization
            ORDER BY organization_name ASC, dt DESC, tournament_id DESC',
            [
                'organizationIds' => $organizationIds,
                'limitPerOrganization' => $limitPerOrganization,
            ],
            [
                'organizationIds' => ArrayParameterType::INTEGER,
            ],
        );

        return array_map(
            fn (array $row): UserAdminRecentTournamentImport => new UserAdminRecentTournamentImport(
                organizationId: (int) $row['organization_id'],
                organizationName: (string) $row['organization_name'],
                tournamentId: (int) $row['tournament_id'],
                tournamentName: (string) $row['tournament_name'],
                date: $this->formatDate((int) $row['dt']),
                urlId: $row['urlid'] !== null ? (int) $row['urlid'] : null,
            ),
            $rows,
        );
    }

    private function formatDate(int $dateCode): string
    {
        $date = DateTimeImmutable::createFromFormat('Ymd', (string) $dateCode);

        return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : (string) $dateCode;
    }
}
