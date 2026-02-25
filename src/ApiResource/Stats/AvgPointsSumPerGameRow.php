<?php

namespace App\ApiResource\Stats;

class AvgPointsSumPerGameRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public float $averagePointsSum,
        public ?float $last24MonthsAveragePointsSum,
        public ?float $last12MonthsAveragePointsSum,
    ) {
    }
}
