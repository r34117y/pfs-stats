<?php

declare(strict_types=1);

namespace App\ClubTournamentImport;

final readonly class ClubTournamentImportResult
{
    /**
     * @param list<int> $createdPlayerIds
     * @param list<int> $linkedPlayerIds
     */
    public function __construct(
        public int $tournamentId,
        public int $legacyTournamentId,
        public int $playersCount,
        public int $gamesCount,
        public array $createdPlayerIds,
        public array $linkedPlayerIds,
    ) {
    }
}
