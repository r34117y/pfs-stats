<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserProfile\UserProfile;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserProfileProvider implements ProviderInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

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
            $this->fetchPublicPlayerId($user),
            $user->getEmail() ?? '',
            $user->getYearOfBirth(),
            $user->getPhoto(),
        );
    }

    private function fetchPublicPlayerId(User $user): ?int
    {
        $playerId = $user->getPlayerId();
        if ($playerId === null) {
            return null;
        }

        $organizationId = $this->connection->fetchOne(
            'SELECT id FROM organization WHERE code = :code LIMIT 1',
            ['code' => self::ORGANIZATION_CODE],
        );

        if ($organizationId === false || $organizationId === null) {
            return null;
        }

        $value = $this->connection->fetchOne(
            'SELECT MIN(legacy_player_id)
             FROM (
                SELECT legacy_player_id
                FROM ranking
                WHERE organization_id = :organizationId
                  AND player_id = :playerId
                  AND legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player_id
                FROM tournament_result
                WHERE organization_id = :organizationId
                  AND player_id = :playerId
                  AND legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player_id
                FROM play_summary
                WHERE organization_id = :organizationId
                  AND player_id = :playerId
                  AND legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player1_id AS legacy_player_id
                FROM tournament_game
                WHERE organization_id = :organizationId
                  AND player1_id = :playerId
                  AND legacy_player1_id IS NOT NULL
                UNION ALL
                SELECT legacy_player2_id AS legacy_player_id
                FROM tournament_game
                WHERE organization_id = :organizationId
                  AND player2_id = :playerId
                  AND legacy_player2_id IS NOT NULL
             ) mapped',
            [
                'organizationId' => (int) $organizationId,
                'playerId' => $playerId,
            ],
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }
}
