<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ClubTournamentExistingTournamentFinder
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function findExistingTournamentId(int $organizationId, int $dateCode, string $tournamentName): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT id
             FROM tournament
             WHERE organization_id = :organizationId
               AND dt = :dateCode
               AND LOWER(COALESCE(fullname, name, '')) = LOWER(:fullname)
             LIMIT 1",
            [
                'organizationId' => $organizationId,
                'dateCode' => $dateCode,
                'fullname' => $this->trimToLength($tournamentName, 80),
            ],
        );

        return $value === false || $value === null ? null : (int) $value;
    }

    private function trimToLength(string $value, int $limit): string
    {
        $value = trim($value);

        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit);
    }
}
