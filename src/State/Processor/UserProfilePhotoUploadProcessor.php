<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\UserProfile\UserProfilePhotoUploadResponse;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class UserProfilePhotoUploadProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
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

        $content = @file_get_contents($uploadedFile->getPathname());
        if ($content === false) {
            throw new BadRequestHttpException('Unable to process uploaded file.');
        }

        $mimeType = $uploadedFile->getMimeType() ?? 'application/octet-stream';
        $dummyPhotoUrl = sprintf('data:%s;base64,%s', $mimeType, base64_encode($content));

        return new UserProfilePhotoUploadResponse(
            'Photo uploaded to dummy endpoint (not persisted).',
            $dummyPhotoUrl,
        );
    }
}
