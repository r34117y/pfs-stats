<?php

namespace App\Service\PlayerTournaments;

use App\ApiResource\PlayerTournaments\PlayerTournaments;

interface PlayerTournamentsServiceInterface
{
    public function getPlayerTournaments(int $playerId): PlayerTournaments;
}
