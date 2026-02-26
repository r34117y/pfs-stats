<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PagesController extends AbstractController
{
    #[Route('/', name: 'app_ranking_page')]
    #[Route('/rank', name: 'app_current_ranking_page')]
    public function rank(): Response
    {
        return $this->render('static/rank.html.twig');
    }

    #[Route('/old-rank', name: 'app_old_ranking_page')]
    public function oldRank(): Response
    {
        return $this->render('static/old_rank.html.twig');
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

    #[Route('/stats/avg-opponents-points', name: 'app_stats_avg_opponents_points_page', methods: ['GET'])]
    public function statsAvgOpponentsPoints(): Response
    {
        return $this->render('static/stats_avg_opponents_points.html.twig');
    }

    #[Route('/stats/avg-points-sum', name: 'app_stats_avg_points_sum_page', methods: ['GET'])]
    public function statsAvgPointsSum(): Response
    {
        return $this->render('static/stats_avg_points_sum.html.twig');
    }

    #[Route('/stats/avg-points-difference', name: 'app_stats_avg_points_difference_page', methods: ['GET'])]
    public function statsAvgPointsDifference(): Response
    {
        return $this->render('static/stats_avg_points_difference.html.twig');
    }

    #[Route('/stats/games-over-400', name: 'app_stats_games_over_400_page', methods: ['GET'])]
    public function statsGamesOver400(): Response
    {
        return $this->render('static/stats_games_over_400.html.twig');
    }

    #[Route('/stats/rank-all-games', name: 'app_stats_rank_all_games_page', methods: ['GET'])]
    public function statsRankAllGames(): Response
    {
        return $this->render('static/stats_rank_all_games.html.twig');
    }

    #[Route('/stats/highest-rank', name: 'app_stats_highest_rank_page', methods: ['GET'])]
    public function statsHighestRank(): Response
    {
        return $this->render('static/stats_highest_rank.html.twig');
    }

    #[Route('/stats/highest-rank-position', name: 'app_stats_highest_rank_position_page', methods: ['GET'])]
    public function statsHighestRankPosition(): Response
    {
        return $this->render('static/stats_highest_rank_position.html.twig');
    }

    #[Route('/stats/ranking-leaders', name: 'app_stats_ranking_leaders_page', methods: ['GET'])]
    public function statsRankingLeaders(): Response
    {
        return $this->render('static/stats_ranking_leaders.html.twig');
    }

    #[Route('/stats/different-opponents', name: 'app_stats_different_opponents_page', methods: ['GET'])]
    public function statsDifferentOpponents(): Response
    {
        return $this->render('static/stats_different_opponents.html.twig');
    }

    #[Route('/stats/most-small-points', name: 'app_stats_most_small_points_page', methods: ['GET'])]
    public function statsMostSmallPoints(): Response
    {
        return $this->render('static/stats_most_small_points.html.twig');
    }

    #[Route('/stats/least-small-points', name: 'app_stats_least_small_points_page', methods: ['GET'])]
    public function statsLeastSmallPoints(): Response
    {
        return $this->render('static/stats_least_small_points.html.twig');
    }

    #[Route('/stats/highest-points-sum', name: 'app_stats_highest_points_sum_page', methods: ['GET'])]
    public function statsHighestPointsSum(): Response
    {
        return $this->render('static/stats_highest_points_sum.html.twig');
    }

    #[Route('/stats/lowest-points-sum', name: 'app_stats_lowest_points_sum_page', methods: ['GET'])]
    public function statsLowestPointsSum(): Response
    {
        return $this->render('static/stats_lowest_points_sum.html.twig');
    }

    #[Route('/stats/highest-victory', name: 'app_stats_highest_victory_page', methods: ['GET'])]
    public function statsHighestVictory(): Response
    {
        return $this->render('static/stats_highest_victory.html.twig');
    }

    #[Route('/stats/highest-draw', name: 'app_stats_highest_draw_page', methods: ['GET'])]
    public function statsHighestDraw(): Response
    {
        return $this->render('static/stats_highest_draw.html.twig');
    }

    #[Route('/tournaments', name: 'app_tournaments_page')]
    public function tournaments(): Response
    {
        return $this->render('static/tournaments.html.twig');
    }

    #[Route('/games', name: 'app_games_page', methods: ['GET'])]
    public function games(): Response
    {
        return $this->render('static/games.html.twig');
    }

    #[Route('/games/{id<\d+-\d+-\d+>}', name: 'app_game_details_page', methods: ['GET'])]
    public function gameDetails(string $id): Response
    {
        return $this->render('static/game_details.html.twig', [
            'gameId' => $id,
        ]);
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
