<?php

namespace App\ApiResource\Ranking;

final readonly class RankingRow
{
    public function __construct(
        public int $position,
        public string $nameShow,
        public string $nameAlph,
        public int $playerId,
        public ?string $photo,
        public string $rank,
        public int $numberOfGames,
        public ?string $rankDelta,
        /** '+' if player just entered the ranking */
        public int|string|null $positionDelta,
        public string $slug,
    )
    {
    }
}
