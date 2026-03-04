<?php

namespace App\PfsTournamentImport;

final readonly class ParsedTournamentRoundGame
{
    public function __construct(
        public int $round,
        public int $table,
        public string $hostName,
        public ?string $guestName,
        public ?int $hostScore,
        public ?int $guestScore,
        public bool $isBye = false,
    ) {
    }
}
