<?php

namespace App\ApiResource\Stats;

class AvgOpponentsPointsPerGameRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public float $averageOpponentPoints,
        public float $last24MonthsAverageOpponentPoints,
        public float $last12MonthsAverageOpponentPoints,
    ) {
    }
}
