<?php

namespace App\ApiResource\Stats;

class HighestRankRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public float $highestRank,
        public ?float $highestRank24Months,
        public ?float $highestRank12Months,
    ) {
    }
}
