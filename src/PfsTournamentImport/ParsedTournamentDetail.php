<?php

namespace App\PfsTournamentImport;

final readonly class ParsedTournamentDetail
{
    public function __construct(
        public string $label,
        public string $value,
    ) {
    }
}
