<?php

namespace App\ApiResource\PlayerTournamentSummary;

class PlayerTournamentSummaryGame
{
    public function __construct(
        public int $round,
        public ?int $tableNumber,
        public bool $wasFirstToPlay,
        public string $result,
        public int $opponentId,
        public string $opponentName,
        public float $achievedRank,
        public int $points,
        public int $pointsLost,
        public int $pointsSum,
        public float $scalp,
    ) {
    }
}
