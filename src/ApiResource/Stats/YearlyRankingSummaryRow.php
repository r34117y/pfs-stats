<?php

namespace App\ApiResource\Stats;

class YearlyRankingSummaryRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $gamesCount,
        public float $rank,
    ) {
    }
}
