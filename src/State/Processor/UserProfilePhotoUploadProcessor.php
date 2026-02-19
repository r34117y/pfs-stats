<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\UserProfile\UserProfilePhotoUploadResponse;
use App\Entity\User;
use App\Service\UserPhotoStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class UserProfilePhotoUploadProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private UserPhotoStorageService $userPhotoStorageService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserProfilePhotoUploadResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Unauthorized.');
        }

        $request = $this->requestStack->getCurrentRequest();
        $uploadedFile = $request?->files->get('photo');
        if (!$uploadedFile instanceof UploadedFile) {
            throw new BadRequestHttpException('Photo file is required.');
        }

        $oldPhotoPath = $user->getPhoto();
        $newPhotoPath = $this->userPhotoStorageService->storeCompressedPhoto($uploadedFile, $user->getId() ?? 0);
        $user->setPhoto($newPhotoPath);
        $this->entityManager->flush();
        $this->userPhotoStorageService->deleteManagedPhoto($oldPhotoPath);

        return new UserProfilePhotoUploadResponse(
            'Photo uploaded successfully.',
            $newPhotoPath,
        );
    }
}
