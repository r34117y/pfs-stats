<?php

namespace App\Service;

use App\ApiResource\TournamentsList\TournamentsList;

interface TournamentListServiceInterface
{
    public function getTournaments(): TournamentsList;
}
