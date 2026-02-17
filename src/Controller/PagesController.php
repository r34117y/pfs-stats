<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PagesController extends AbstractController
{
    #[Route('/', name: 'app_ranking_page')]
    public function rank(): Response
    {
        return $this->render('static/rank.html.twig');
    }

    #[Route('/players', name: 'app_players_page')]
    public function players(): Response
    {
        return $this->render('static/players.html.twig');
    }

    #[Route('/players/{playerId<\d+>}', name: 'app_player_profile_page', methods: ['GET'])]
    public function playerProfile(int $playerId): Response
    {
        return $this->render('static/player_profile.html.twig', [
            'playerId' => $playerId,
        ]);
    }

    #[Route('/players/{playerId<\d+>}/tournaments', name: 'app_player_tournaments_page', methods: ['GET'])]
    public function playerTournaments(int $playerId): Response
    {
        return $this->render('static/player_tournaments.html.twig', [
            'playerId' => $playerId,
        ]);
    }

    #[Route('/players/{playerId<\d+>}/rank-history', name: 'app_player_rank_history_page', methods: ['GET'])]
    public function playerRankHistory(int $playerId): Response
    {
        return $this->render('static/player_rank_history.html.twig', [
            'playerId' => $playerId,
        ]);
    }

    #[Route('/players/{playerId<\d+>}/records', name: 'app_player_records_page', methods: ['GET'])]
    public function playerRecords(int $playerId): Response
    {
        return $this->render('static/player_records.html.twig', [
            'playerId' => $playerId,
        ]);
    }

    #[Route('/players/{playerId<\d+>}/game-balance', name: 'app_player_game_balance_page', methods: ['GET'])]
    public function playerGameBalance(int $playerId): Response
    {
        return $this->render('static/player_game_balance.html.twig', [
            'playerId' => $playerId,
        ]);
    }

    #[Route('/clubs', name: 'app_clubs_page')]
    public function clubs(): Response
    {
        return $this->render('static/clubs.html.twig');
    }

    #[Route('/stats', name: 'app_stats_page')]
    public function stats(): Response
    {
        return $this->render('static/stats.html.twig');
    }

    #[Route('/stats/all-times-results', name: 'app_stats_all_times_results_page', methods: ['GET'])]
    public function statsAllTimesResults(): Response
    {
        return $this->render('static/stats_all_times_results.html.twig');
    }

    #[Route('/stats/all-time-summary', name: 'app_stats_all_time_summary_page', methods: ['GET'])]
    public function statsAllTimeSummary(): Response
    {
        return $this->render('static/stats_all_time_summary.html.twig');
    }

    #[Route('/stats/games', name: 'app_stats_games_page', methods: ['GET'])]
    public function statsGames(): Response
    {
        return $this->render('static/stats_games.html.twig');
    }

    #[Route('/stats/games-won', name: 'app_stats_games_won_page', methods: ['GET'])]
    public function statsGamesWon(): Response
    {
        return $this->render('static/stats_games_won.html.twig');
    }

    #[Route('/stats/tournaments', name: 'app_stats_tournaments_page', methods: ['GET'])]
    public function statsTournaments(): Response
    {
        return $this->render('static/stats_tournaments.html.twig');
    }

    #[Route('/stats/avg-points-per-game', name: 'app_stats_avg_points_per_game_page', methods: ['GET'])]
    public function statsAvgPointsPerGame(): Response
    {
        return $this->render('static/stats_avg_points_per_game.html.twig');
    }

    #[Route('/tournaments', name: 'app_tournaments_page')]
    public function tournaments(): Response
    {
        return $this->render('static/tournaments.html.twig');
    }

    #[Route('/tournaments/{tournamentId<\d+>}', name: 'app_tournament_details_page', methods: ['GET'])]
    public function tournamentDetails(int $tournamentId): Response
    {
        return $this->render('static/tournament_details.html.twig', [
            'tournamentId' => $tournamentId,
        ]);
    }

    #[Route('/tournaments/{tournamentId<\d+>}/players/{playerId<\d+>}', name: 'app_player_tournament_summary_page', methods: ['GET'])]
    public function playerTournamentSummary(int $tournamentId, int $playerId): Response
    {
        return $this->render('static/player_tournament_summary.html.twig', [
            'tournamentId' => $tournamentId,
            'playerId' => $playerId,
        ]);
    }
}
