<?php

namespace App\ApiResource\Stats;

class DifferentOpponentsRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $opponentsCount,
        public int $last24MonthsOpponentsCount,
        public int $last12MonthsOpponentsCount,
    ) {
    }
}
