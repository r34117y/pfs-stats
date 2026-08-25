<?php

namespace App\Service;

final readonly class TournamentRoundImportResult
{
    /**
     * @param array<string, int> $rankingRowsInsertedByType
     */
    public function __construct(
        public int $tournamentId,
        public array $rankingRowsInsertedByType,
    ) {
    }

    public function rankingRowsInserted(string $rankingType): int
    {
        return $this->rankingRowsInsertedByType[$rankingType] ?? 0;
    }
}
