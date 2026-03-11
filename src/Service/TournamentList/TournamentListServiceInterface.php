<?php

namespace App\Service\TournamentList;

use App\ApiResource\TournamentsList\TournamentsList;

interface TournamentListServiceInterface
{
    public function getTournaments(): TournamentsList;
}
