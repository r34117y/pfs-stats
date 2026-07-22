<?php

namespace App\PfsTournamentImport;

final readonly class ParsedTournamentRankingRow
{
    public function __construct(
        public int $position,
        public string $playerName,
        public string $city,
        public float $rank,
        public int $scalp,
        public int $games,
        public bool $main,
    ) {
    }
}
