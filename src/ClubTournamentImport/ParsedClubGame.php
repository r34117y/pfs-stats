<?php

declare(strict_types=1);

namespace App\ClubTournamentImport;

final readonly class ParsedClubGame
{
    public function __construct(
        public int $round,
        public ?int $table,
        public ?int $opponentPosition,
        public string $opponentName,
        public ?int $opponentRank,
        public ?string $result,
        public ?int $pointsFor,
        public ?int $pointsAgainst,
        public ?int $scalp,
        public bool $isBye,
    ) {
    }
}
