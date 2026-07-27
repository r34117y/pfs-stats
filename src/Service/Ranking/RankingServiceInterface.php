<?php

namespace App\Service\Ranking;

use App\ApiResource\Ranking\GetRanking;

interface RankingServiceInterface
{
    public function getRanking(int $organizationId, string $rankingType = 'f'): GetRanking;
}
