<?php

namespace App\Service\Clubs;

use App\ApiResource\ClubsList\ClubsList;

interface ClubsServiceInterface
{
    public function getClubsList(): ClubsList;
}
