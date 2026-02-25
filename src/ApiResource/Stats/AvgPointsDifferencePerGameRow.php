<?php

namespace App\ApiResource\Stats;

class AvgPointsDifferencePerGameRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public float $averagePointsDifference,
        public ?float $last24MonthsAveragePointsDifference,
        public ?float $last12MonthsAveragePointsDifference,
    ) {
    }
}
