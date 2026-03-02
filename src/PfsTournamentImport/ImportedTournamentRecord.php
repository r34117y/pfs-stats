<?php

namespace App\PfsTournamentImport;

final readonly class ImportedTournamentRecord
{
    public function __construct(
        public int $id,
        public ?int $urlId,
    ) {
    }

    public function getDatePrefix(): int
    {
        return intdiv($this->id, 10);
    }

    public function getSuffix(): int
    {
        return $this->id % 10;
    }
}
