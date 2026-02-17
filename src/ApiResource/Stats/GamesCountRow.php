<?php

namespace App\ApiResource\Stats;

class GamesCountRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $gamesCount,
        public int $last24MonthsGamesCount,
        public int $last12MonthsGamesCount,
    ) {
    }
}
