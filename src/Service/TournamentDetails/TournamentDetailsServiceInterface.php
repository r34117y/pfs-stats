<?php

namespace App\Service\TournamentDetails;

use App\ApiResource\TournamentDetails\TournamentDetails;
use App\ApiResource\TournamentDetails\TournamentResults;

interface TournamentDetailsServiceInterface
{
    public function getTournamentDetails(int $tournamentId, int $orgId): TournamentDetails;
    public function getTournamentResults(int $tournamentId, int $orgId): TournamentResults;
}
