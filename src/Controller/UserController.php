<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UserController extends AbstractController
{
    #[Route('/user/profile', name: 'app_user_profile_page', methods: ['GET'])]
    public function profile(): Response
    {
        return $this->render('user/profile.html.twig');
    }

    #[Route('/user/profile/data', name: 'app_user_profile_data', methods: ['GET'])]
    public function profileData(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'yearOfBirth' => $user->getYearOfBirth(),
            'photo' => $user->getPhoto(),
        ]);
    }

    #[Route('/user/profile/save', name: 'app_user_profile_save', methods: ['POST'])]
    public function saveProfile(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $request->toArray();
        } catch (\JsonException) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $email = isset($payload['email']) ? trim((string) $payload['email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['message' => 'Invalid email address.'], Response::HTTP_BAD_REQUEST);
        }

        $yearOfBirth = null;
        $dateOfBirth = trim((string) ($payload['dateOfBirth'] ?? ''));
        if ($dateOfBirth !== '') {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateOfBirth);
            if (!$date || $date->format('Y-m-d') !== $dateOfBirth) {
                return new JsonResponse(['message' => 'Invalid date of birth. Use YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
            }
            $yearOfBirth = (int) $date->format('Y');
        }

        $user->setEmail($email);
        $user->setYearOfBirth($yearOfBirth);

        $entityManager->flush();

        return new JsonResponse([
            'message' => 'Profile saved.',
            'profile' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'yearOfBirth' => $user->getYearOfBirth(),
                'photo' => $user->getPhoto(),
            ],
        ]);
    }

    #[Route('/user/profile/photo/upload', name: 'app_user_profile_photo_upload', methods: ['POST'])]
    public function uploadPhoto(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $uploadedFile = $request->files->get('photo');
        if (!$uploadedFile instanceof UploadedFile) {
            return new JsonResponse(['message' => 'Photo file is required.'], Response::HTTP_BAD_REQUEST);
        }

        $content = @file_get_contents($uploadedFile->getPathname());
        if ($content === false) {
            return new JsonResponse(['message' => 'Unable to process uploaded file.'], Response::HTTP_BAD_REQUEST);
        }

        $mimeType = $uploadedFile->getMimeType() ?? 'application/octet-stream';
        $dummyPhotoUrl = sprintf('data:%s;base64,%s', $mimeType, base64_encode($content));

        return new JsonResponse([
            'message' => 'Photo uploaded to dummy endpoint (not persisted).',
            'photoUrl' => $dummyPhotoUrl,
        ]);
    }
}
