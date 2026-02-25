<?php

namespace App\ApiResource\Stats;

class GamesOver400Row
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $gamesOver400,
        public float $gamesOver400Percent,
        public ?int $gamesOver40024Months,
        public ?float $gamesOver40024MonthsPercent,
        public ?int $gamesOver40012Months,
        public ?float $gamesOver40012MonthsPercent,
    ) {
    }
}
