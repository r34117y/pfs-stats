<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\UserAdmin\UserAdminOrganization;
use App\ApiResource\UserAdmin\UserAdminProfile;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait UserAdminContextTrait
{
    private function getAuthenticatedUser(Security $security): User
    {
        $user = $security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Unauthorized.');
        }

        return $user;
    }

    /**
     * @return array{profile: UserAdminProfile, organizations: list<UserAdminOrganization>}
     */
    private function getAdminContext(User $user, Connection $connection): array
    {
        $playerId = $user->getPlayerId();
        if ($playerId === null) {
            throw new AccessDeniedHttpException('Organization admin role is required.');
        }

        $rows = $connection->fetchAllAssociative(
            'SELECT o.id, o.code, o.name, p.slug
            FROM player_organization po
            INNER JOIN organization o ON o.id = po.organization_id
            INNER JOIN player p ON p.id = po.player_id
            WHERE po.player_id = :playerId
                AND po.is_admin = true
            ORDER BY o.name ASC',
            ['playerId' => $playerId],
        );

        if ($rows === []) {
            throw new AccessDeniedHttpException('Organization admin role is required.');
        }

        $slug = is_string($rows[0]['slug'] ?? null) ? trim($rows[0]['slug']) : null;
        $organizations = array_map(
            static fn (array $row): UserAdminOrganization => new UserAdminOrganization(
                (int) $row['id'],
                (string) $row['code'],
                (string) $row['name'],
            ),
            $rows,
        );

        return [
            'profile' => new UserAdminProfile(
                $slug === '' ? null : $slug,
                true,
            ),
            'organizations' => $organizations,
        ];
    }
}
