<?php

namespace App\Service;

use App\ApiResource\PlayerProfile\PlayerProfile;

interface PlayerProfileServiceInterface
{
    public function getPlayerProfile(int $playerId): PlayerProfile;
}
