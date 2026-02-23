<?php

namespace App\ApiResource\ClubsList;

final readonly class ClubsListClub
{
    public function __construct(
        public int $id,
        public string $name,
        public string $city,
    ) {
    }
}
