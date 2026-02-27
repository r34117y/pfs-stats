<?php

namespace App\ApiResource\Stats;

class HighestAvgPointsDiffRow
{
    public function __construct(
        public int $position,
        public float $points,
        public int $playerId,
        public string $playerName,
        public string $result,
        public int $tournamentId,
        public string $tournamentName,
    ) {
    }
}
