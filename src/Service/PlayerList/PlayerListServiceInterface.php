<?php

namespace App\Service;

use App\ApiResource\PlayersList\PlayersList;

interface PlayerListServiceInterface
{
    public function getPlayers(): PlayersList;
}
