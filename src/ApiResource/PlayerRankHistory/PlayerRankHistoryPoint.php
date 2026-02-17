<?php

namespace App\ApiResource\PlayerRankHistory;

class PlayerRankHistoryPoint
{
    public function __construct(
        public int $tournamentId,
        public string $tournamentName,
        public string $date,
        public float $rank,
    ) {
    }
}
