<?php

namespace App\PfsTournamentImport;

final readonly class PfsTourImportRow
{
    public function __construct(
        public int $id,
        public int $dt,
        public string $name,
        public string $fullname,
        public int $winner,
        public float $trank,
        public int $players,
        public int $rounds,
        public string $rrecreated,
        public string $team,
        public int $mcategory,
        public float $wksum,
        public int $sertour,
        public int $start,
        public ?string $referee,
        public ?string $place,
        public ?string $organizer,
        public int $urlid,
    ) {
    }
}
