<?php

namespace App\ApiResource\TournamentDetails;

class TournamentResultRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $gamesCount,
        public float $rankBefore,
        public int $wins,
        public int $totalPointsScored,
        public int $diff,
        public int $sumPoints,
        public float $scalp,
        public float $rankAchieved,
        public float $avgOpponentRank,
    ) {
    }
}
