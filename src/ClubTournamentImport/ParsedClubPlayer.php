<?php

declare(strict_types=1);

namespace App\ClubTournamentImport;

final readonly class ParsedClubPlayer
{
    /**
     * @param list<ParsedClubGame> $games
     */
    public function __construct(
        public int $position,
        public string $name,
        public int $initialRank,
        public string $city,
        public array $games,
        public float $achievedRank,
    ) {
    }
}
