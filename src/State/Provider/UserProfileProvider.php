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

        return new UserProfile(
            $user->getId() ?? 0,
            $this->fetchPublicPlayerSlug($user),
            $user->getEmail() ?? '',
            $user->getYearOfBirth(),
            $user->getPhoto(),
        );
    }

    /**
     * @throws Exception
     */
    private function fetchPublicPlayerSlug(User $user): ?string
    {
        $playerId = $user->getPlayerId();
        if ($playerId === null) {
            return null;
        }

        $value = $this->connection->fetchOne(
            'SELECT slug FROM player WHERE id = :playerId LIMIT 1',
            ['playerId' => $playerId],
        );

        if (!is_string($value)) {
            return null;
        }

        $slug = trim($value);

        return $slug === '' ? null : $slug;
    }
}
