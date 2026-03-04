<?php

namespace App\PfsTournamentImport;

final readonly class PfsTournamentImportComparison
{
    /**
     * @param list<string> $findings
     */
    public function __construct(
        public bool $matches,
        public array $findings,
    ) {
    }
}
