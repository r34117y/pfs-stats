<?php

namespace App\PfsTournamentImport;

final readonly class TournamentImportMetadata
{
    public function __construct(
        public int $tournamentId,
        public int $urlId,
        public string $shortName,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public ?string $team = null,
        public ?int $mcategory = null,
        public ?int $sertour = null,
    ) {
    }
}
