<?php

namespace App\ApiResource\Stats;

class LongestLossStreaksRow
{
    /**
     * @param array<int, array{id: int, name: string}> $tournaments
     */
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $lossesStreak,
        public array $tournaments,
        public int $currentStreak,
    ) {
    }
}
