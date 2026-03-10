<?php

namespace App\Service;

use App\ApiResource\ClubsList\ClubsList;

interface ClubsServiceInterface
{
    public function getClubsList(): ClubsList;
}
