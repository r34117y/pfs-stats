<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class UserOrganizationAdminChecker
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function assertOrganizationAdmin(User $user, int $organizationId): void
    {
        $playerId = $user->getPlayerId();
        if ($playerId === null) {
            throw new AccessDeniedHttpException('Organization admin role is required.');
        }

        $isAdmin = (bool) $this->connection->fetchOne(
            'SELECT 1
            FROM player_organization
            WHERE player_id = :playerId
                AND organization_id = :organizationId
                AND is_admin = true',
            [
                'playerId' => $playerId,
                'organizationId' => $organizationId,
            ],
        );

        if (!$isAdmin) {
            throw new AccessDeniedHttpException('Organization admin role is required.');
        }
    }
}
