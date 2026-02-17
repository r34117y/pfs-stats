<?php

namespace App\ApiResource\PlayerRankHistory;

class PlayerRankMilestone
{
    public function __construct(
        public int $milestone,
        public string $date,
        public int $tournamentId,
        public string $tournamentName,
        public float $rank,
    ) {
    }
}
