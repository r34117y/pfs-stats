<?php

namespace App\Service;

use App\ApiResource\PlayerTournamentSummary\PlayerTournamentSummary;

interface PlayerTournamentSummaryServiceInterface
{
    public function getSummary(int $tournamentId, int $playerId): PlayerTournamentSummary;
}
