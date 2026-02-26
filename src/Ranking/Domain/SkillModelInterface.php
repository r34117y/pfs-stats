<?php

declare(strict_types=1);

namespace App\Ranking\Domain;

interface SkillModelInterface
{
    /**
     * @param list<array{player1Id: int, player2Id: int, score1: float}> $games
     * @param array<int, int> $gamesCountByPlayer
     */
    public function fit(array $games, array $gamesCountByPlayer, float $ciLevel): SkillModelResult;
}

