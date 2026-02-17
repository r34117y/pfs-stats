<?php

namespace App\ApiResource\Stats;

class TournamentsCountRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $tournamentsCount,
        public int $last24MonthsTournamentsCount,
        public int $last12MonthsTournamentsCount,
    ) {
    }
}
