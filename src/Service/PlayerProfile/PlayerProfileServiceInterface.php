<?php

namespace App\Service\PlayerProfile;

use App\ApiResource\PlayerProfile\PlayerProfile;

interface PlayerProfileServiceInterface
{
    public function getPlayerProfile(string $playerSlug): PlayerProfile;
}
