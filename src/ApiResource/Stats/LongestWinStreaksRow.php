<?php

namespace App\ApiResource\Stats;

class LongestWinStreaksRow
{
    /**
     * @param array<int, array{id: int, name: string}> $tournaments
     */
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $winsStreak,
        public array $tournaments,
        public int $currentStreak,
    ) {
    }
}
