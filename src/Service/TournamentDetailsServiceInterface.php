<?php

namespace App\Service;

use App\ApiResource\TournamentDetails\TournamentDetails;
use App\ApiResource\TournamentDetails\TournamentResults;

interface TournamentDetailsServiceInterface
{
    public function getTournamentDetails(int $tournamentId): TournamentDetails;
    public function getTournamentResults(int $tournamentId): TournamentResults;
}
