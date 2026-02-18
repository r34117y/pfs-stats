<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
            'roles' => $user->getRoles(),
            'yearOfBirth' => $user->getYearOfBirth(),
            'photo' => $user->getPhoto(),
            'playerId' => $user->getPlayerId(),
        ]);
    }
}
