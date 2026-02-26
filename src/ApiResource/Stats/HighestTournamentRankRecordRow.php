<?php

namespace App\ApiResource\Stats;

class HighestTournamentRankRecordRow
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public float $ranking,
        public string $result,
        public int $tournamentId,
        public string $tournamentName,
    ) {
    }
}
