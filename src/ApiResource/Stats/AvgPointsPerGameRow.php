<?php

namespace App\ApiResource\Stats;

class AvgPointsPerGameRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public float $averagePoints,
        public float $last24MonthsAveragePoints,
        public float $last12MonthsAveragePoints,
    ) {
    }
}
