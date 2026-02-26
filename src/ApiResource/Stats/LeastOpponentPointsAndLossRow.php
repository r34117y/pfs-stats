<?php

namespace App\ApiResource\Stats;

class LeastOpponentPointsAndLossRow
{
    public function __construct(
        public int $position,
        public int $points,
        public int $playerId,
        public string $playerName,
        public int $opponentId,
        public string $opponentName,
        public string $score,
        public int $tournamentId,
        public string $tournamentName,
    ) {
    }
}
