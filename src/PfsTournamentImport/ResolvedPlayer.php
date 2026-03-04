<?php

namespace App\PfsTournamentImport;

final readonly class ResolvedPlayer
{
    public function __construct(
        public int $id,
        public string $nameShow,
        public string $nameAlph,
        public float $seedRank,
        public bool $isNew,
    ) {
    }
}
