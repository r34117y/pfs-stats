<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\UserProfile\UserProfile;
use App\ApiResource\UserProfile\UserProfileSave;
use App\ApiResource\UserProfile\UserProfileSaveResponse;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserProfileSaveProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserProfileSaveResponse
    {
        if (!$data instanceof UserProfileSave) {
            throw new BadRequestHttpException('Invalid payload.');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Unauthorized.');
        }

        $email = trim($data->email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('Invalid email address.');
        }

        $yearOfBirth = null;
        $dateOfBirth = trim((string) $data->dateOfBirth);
        if ($dateOfBirth !== '') {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateOfBirth);
            if (!$date || $date->format('Y-m-d') !== $dateOfBirth) {
                throw new BadRequestHttpException('Invalid date of birth. Use YYYY-MM-DD.');
            }
            $yearOfBirth = (int) $date->format('Y');
        }

        $bio = $this->normalizeBio($data->bio);

        $user->setEmail($email);
        $user->setYearOfBirth($yearOfBirth);
        $user->getPlayer()?->setBio($bio);

        $this->entityManager->flush();

        return new UserProfileSaveResponse(
            'Profile saved.',
            new UserProfile(
                $user->getId() ?? 0,
                $user->getPlayer()?->getSlug(),
                $user->getEmail() ?? '',
                $user->getYearOfBirth(),
                $user->getPhoto(),
                $user->getPlayer()?->getBio(),
                $this->isOrganizationAdmin($user),
            )
        );
    }

    private function normalizeBio(?string $bio): ?string
    {
        if ($bio === null) {
            return null;
        }

        $normalizedBio = trim($bio);

        return $normalizedBio === '' ? null : $normalizedBio;
    }

    private function isOrganizationAdmin(User $user): bool
    {
        $playerId = $user->getPlayerId();
        if ($playerId === null) {
            return false;
        }

        $isAdmin = $this->connection->fetchOne(
            'SELECT CASE WHEN EXISTS(
                SELECT 1
                FROM player_organization
                WHERE player_id = :playerId
                    AND is_admin = true
            ) THEN 1 ELSE 0 END',
            ['playerId' => $playerId],
        );

        return (int) $isAdmin === 1;
    }
}
