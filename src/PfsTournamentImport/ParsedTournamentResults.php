<?php

namespace App\PfsTournamentImport;

final readonly class ParsedTournamentResults
{
    /**
     * @param list<ParsedTournamentDetail> $details
     * @param list<ParsedTournamentPlayerResults> $players
     * @param list<ParsedTournamentStandingRow> $standings
     * @param list<ParsedTournamentRoundGame> $roundGames
     */
    public function __construct(
        public string $tournamentName,
        public array $details,
        public array $players,
        public array $standings,
        public array $roundGames,
    ) {
    }

    public function getDetailValue(string $label): ?string
    {
        foreach ($this->details as $detail) {
            if ($detail->label === $label) {
                return $detail->value;
            }
        }

        return null;
    }
}
