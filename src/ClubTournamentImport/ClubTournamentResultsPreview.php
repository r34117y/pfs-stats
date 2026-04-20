<?php

declare(strict_types=1);

namespace App\ClubTournamentImport;

final readonly class ClubTournamentResultsPreview
{
    /**
     * @param list<array<string, int|float|string>> $standings
     */
    public function __construct(
        public ParsedClubTournamentResults $results,
        public array $standings,
        public string $hhText,
    ) {
    }
}
