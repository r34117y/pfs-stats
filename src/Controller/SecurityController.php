<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\PasswordChangeValidator;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            if ($user->requiresPasswordChange()) {
                return $this->redirectToRoute('app_user_change_password');
            }

            return $this->redirectToRoute('app_ranking_page');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/user/change-password', name: 'app_user_change_password', methods: ['GET', 'POST'])]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        PasswordChangeValidator $passwordChangeValidator,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!$user->requiresPasswordChange()) {
            return $this->redirectToRoute('app_user_profile_page');
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            $submittedToken = (string) $request->request->get('_csrf_token', '');
            if (!$this->isCsrfTokenValid('change_password', $submittedToken)) {
                $errors[] = 'Nieprawidlowy token formularza.';
            }

            $password = (string) $request->request->get('password', '');
            $passwordConfirmation = (string) $request->request->get('password_confirmation', '');
            $errors = [...$errors, ...$passwordChangeValidator->validate($password, $passwordConfirmation)];

            if ([] === $errors) {
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $user->setRequiresPasswordChange(false);
                $entityManager->flush();

                $this->addFlash('success', 'Haslo zostalo zmienione.');

                return $this->redirectToRoute('app_user_profile_page');
            }
        }

        return $this->render('security/change_password.html.twig', [
            'errors' => $errors,
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new LogicException('This should never be reached. Logout is handled by the firewall.');
    }
}
