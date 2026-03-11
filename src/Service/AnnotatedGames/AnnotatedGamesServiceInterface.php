<?php

namespace App\Service\AnnotatedGames;

use App\ApiResource\AnnotatedGames\AnnotatedGames;

interface AnnotatedGamesServiceInterface
{
    public function getAnnotatedGames(int $page, ?string $playerName = null, ?string $tournamentName = null): AnnotatedGames;
}
