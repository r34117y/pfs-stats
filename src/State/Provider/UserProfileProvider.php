<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserProfile\UserProfile;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class UserProfileProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserProfile
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Unauthorized.');
        }

        $playerData = $this->fetchPlayerData($user);

        return new UserProfile(
            $user->getId() ?? 0,
            $playerData['slug'],
            $user->getEmail() ?? '',
            $user->getYearOfBirth(),
            $user->getPhoto(),
            $playerData['bio'],
            $playerData['isOrganizationAdmin'],
        );
    }

    /**
     * @return array{slug: ?string, bio: ?string, isOrganizationAdmin: bool}
     * @throws Exception
     */
    private function fetchPlayerData(User $user): array
    {
        $playerId = $user->getPlayerId();
        if ($playerId === null) {
            return [
                'slug' => null,
                'bio' => null,
                'isOrganizationAdmin' => false,
            ];
        }

        $row = $this->connection->fetchAssociative(
            'SELECT p.slug, p.bio, CASE WHEN EXISTS(
                SELECT 1
                FROM player_organization po
                WHERE po.player_id = p.id
                    AND po.is_admin = true
            ) THEN 1 ELSE 0 END AS is_organization_admin
            FROM player p
            WHERE p.id = :playerId
            LIMIT 1',
            ['playerId' => $playerId],
        );

        if ($row === false) {
            return [
                'slug' => null,
                'bio' => null,
                'isOrganizationAdmin' => false,
            ];
        }

        $slug = is_string($row['slug'] ?? null) ? trim($row['slug']) : null;
        $bio = is_string($row['bio'] ?? null) ? $row['bio'] : null;

        return [
            'slug' => $slug === '' ? null : $slug,
            'bio' => $bio,
            'isOrganizationAdmin' => (int) ($row['is_organization_admin'] ?? 0) === 1,
        ];
    }
}
