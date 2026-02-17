<?php

namespace App\ApiResource\PlayerProfile;

class PlayerProfileTournament
{
    public function __construct(
        public int $id,
        public string $name,
        public int $date,
    ) {
    }
}
