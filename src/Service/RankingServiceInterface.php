<?php

namespace App\Service;

use App\ApiResource\Ranking\GetRanking;

interface RankingServiceInterface
{
    public function getRanking(): GetRanking;
}
