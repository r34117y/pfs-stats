<?php

namespace App\Service;

use App\ApiResource\PlayerTournaments\PlayerTournaments;

interface PlayerTournamentsServiceInterface
{
    public function getPlayerTournaments(int $playerId): PlayerTournaments;
}
