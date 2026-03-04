<?php

namespace App\PfsTournamentImport;

final readonly class PfsTourHhImportRow
{
    public function __construct(
        public int $turniej,
        public int $runda,
        public int $stol,
        public int $player1,
        public int $player2,
        public int $result1,
        public int $result2,
        public int $ranko,
        public int $host,
    ) {
    }
}
