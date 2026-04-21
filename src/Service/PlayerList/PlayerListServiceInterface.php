<?php

namespace App\Service\PlayerList;

use App\ApiResource\PlayersList\PlayersList;

interface PlayerListServiceInterface
{
    public function getPlayers(int $organizationId): PlayersList;
}
