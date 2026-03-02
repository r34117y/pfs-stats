<?php

namespace App\PfsTournamentImport;

final readonly class TournamentImportCheckResult
{
    /**
     * @param list<PendingTournamentImport> $pendingImports
     */
    public function __construct(
        public int $year,
        public ?int $latestImportedTournamentId,
        public array $pendingImports,
    ) {
    }
}
