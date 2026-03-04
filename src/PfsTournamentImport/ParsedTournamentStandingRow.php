<?php

namespace App\PfsTournamentImport;

final readonly class ParsedTournamentStandingRow
{
    public function __construct(
        public int $place,
        public string $playerName,
        public string $city,
        public float $tournamentRank,
        public float $bigPoints,
        public int $smallPoints,
        public int $scalps,
        public int $pointsDiff,
    ) {
    }
}
