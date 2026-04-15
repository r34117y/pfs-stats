<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UserController extends AbstractController
{
    #[Route('/user/profile', name: 'app_user_profile_page', methods: ['GET'])]
    public function profile(): Response
    {
        return $this->render('user/profile.html.twig');
    }

    #[Route('/user/tournament-results/add', name: 'app_user_add_tournament_results_page', methods: ['GET'])]
    public function addTournamentResults(): Response
    {
        return $this->render('user/add_tournament_results.html.twig');
    }

    #[Route('/user/players/manage', name: 'app_user_manage_players_page', methods: ['GET'])]
    public function managePlayers(): Response
    {
        return $this->render('user/manage_players.html.twig');
    }
}
