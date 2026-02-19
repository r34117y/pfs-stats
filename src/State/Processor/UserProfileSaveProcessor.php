<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\UserProfile\UserProfile;
use App\ApiResource\UserProfile\UserProfileSave;
use App\ApiResource\UserProfile\UserProfileSaveResponse;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class UserProfileSaveProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
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

        $user->setEmail($email);
        $user->setYearOfBirth($yearOfBirth);

        $this->entityManager->flush();

        return new UserProfileSaveResponse(
            'Profile saved.',
            new UserProfile(
                $user->getId() ?? 0,
                $user->getEmail() ?? '',
                $user->getYearOfBirth(),
                $user->getPhoto(),
            )
        );
    }
}
