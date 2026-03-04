<?php

namespace App\PfsTournamentImport;

final readonly class ParsedTournamentPlayerResults
{
    /**
     * @param list<ParsedTournamentPlayerGame> $games
     */
    public function __construct(
        public string $playerName,
        public float $tournamentRank,
        public string $city,
        public array $games,
        public int $totalScalp,
        public int $roundsPlayed,
        public float $rankAchieved,
    ) {
    }
}
