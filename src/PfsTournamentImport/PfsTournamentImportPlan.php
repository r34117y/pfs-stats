<?php

namespace App\PfsTournamentImport;

final readonly class PfsTournamentImportPlan
{
    /**
     * @param list<PfsPlayerImportRow> $newPlayers
     * @param list<PfsTourWynImportRow> $tournamentResults
     * @param list<PfsTourHhImportRow> $tournamentGames
     * @param list<string> $warnings
     */
    public function __construct(
        public PfsTourImportRow $tournament,
        public array $newPlayers,
        public array $tournamentResults,
        public array $tournamentGames,
        public array $warnings,
    ) {
    }
}
