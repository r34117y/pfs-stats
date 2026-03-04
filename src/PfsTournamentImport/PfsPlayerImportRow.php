<?php

namespace App\PfsTournamentImport;

final readonly class PfsPlayerImportRow
{
    public function __construct(
        public int $id,
        public string $nameShow,
        public string $nameAlph,
        public string $utype = 'P',
        public string $cached = 'N',
    ) {
    }
}
