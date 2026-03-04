<?php

namespace App\PfsTournamentImport;

final readonly class PfsTourWynImportRow
{
    public function __construct(
        public int $turniej,
        public int $player,
        public int $place,
        public int $gwin,
        public int $glost,
        public int $gdraw,
        public int $games,
        public float $trank,
        public float $brank,
        public float $points,
        public float $pointo,
        public int $hostgames,
        public int $hostwin,
        public int $masters = 0,
    ) {
    }
}
