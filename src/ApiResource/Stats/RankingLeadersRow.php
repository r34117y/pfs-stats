<?php

namespace App\ApiResource\Stats;

class RankingLeadersRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $daysOnTop,
        public string $firstTournamentName,
        public string $lastTournamentName,
    ) {
    }
}
