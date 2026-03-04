<?php

namespace App\PfsTournamentImport;

final readonly class ParsedTournamentPlayerGame
{
    public function __construct(
        public int $round,
        public int $table,
        public string $opponentName,
        public ?float $opponentRank,
        public ?string $result,
        public ?int $pointsFor,
        public ?int $pointsAgainst,
        public ?int $scalp,
        public bool $isBye = false,
    ) {
    }
}
