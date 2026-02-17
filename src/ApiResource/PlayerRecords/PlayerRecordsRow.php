<?php

namespace App\ApiResource\PlayerRecords;

class PlayerRecordsRow
{
    public function __construct(
        public int $position,
        public ?int $points = null,
        public ?string $opponent = null,
        public ?string $score = null,
        public ?string $tournament = null,
        public ?int $streak = null,
        public ?string $tournaments = null,
        public ?string $firstTournament = null,
        public ?string $lastTournament = null,
    ) {
    }
}
