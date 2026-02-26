<?php

namespace App\ApiResource\Stats;

class LongestStreakSumMin750Row
{
    /**
     * @param array<int, array{id: int, name: string}> $tournaments
     */
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $gamesStreak,
        public array $tournaments,
        public int $currentStreak,
    ) {
    }
}
