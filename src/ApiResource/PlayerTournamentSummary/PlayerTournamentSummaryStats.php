<?php

namespace App\ApiResource\PlayerTournamentSummary;

class PlayerTournamentSummaryStats
{
    public function __construct(
        public int $position,
        public float $rankAchieved,
        public float $avgOpponentRank,
        public float $avgPointsPerGame,
        public float $avgOpponentPointsPerGame,
        public float $avgPointsPerGameWon,
        public float $avgOpponentPointsPerGameWon,
        public float $avgPointsPerGameLost,
        public float $avgOpponentPointsPerGameLost,
        public float $avgPointsSum,
        public float $avgDiffWon,
        public float $avgDiffLost,
    ) {
    }
}
