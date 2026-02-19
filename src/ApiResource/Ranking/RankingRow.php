<?php

namespace App\ApiResource\Ranking;

class RankingRow
{
    public function __construct(
        public int $position,
        public string $nameShow,
        public string $nameAlph,
        public int $playerId,
        public ?string $photo,
        public float $rank,
        public int $numberOfGames,
        public ?float $rankDelta,
        /** null if player just entered the ranking */
        public ?int $positionDelta
    )
    {
    }
}
