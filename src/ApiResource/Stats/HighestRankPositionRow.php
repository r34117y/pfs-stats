<?php

namespace App\ApiResource\Stats;

class HighestRankPositionRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $highestRankPosition,
        public ?int $highestRankPosition24Months,
        public ?int $highestRankPosition12Months,
    ) {
    }
}
