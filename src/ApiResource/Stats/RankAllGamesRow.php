<?php

namespace App\ApiResource\Stats;

class RankAllGamesRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public float $rankAllGames,
        public ?float $rank24Months,
        public ?float $rank12Months,
    ) {
    }
}
