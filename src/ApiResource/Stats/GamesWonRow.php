<?php

namespace App\ApiResource\Stats;

class GamesWonRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $gamesWon,
        public float $gamesWonPercent,
        public int $gamesWon24Months,
        public float $gamesWon24MonthsPercent,
        public int $gamesWon12Months,
        public float $gamesWon12MonthsPercent,
    ) {
    }
}
