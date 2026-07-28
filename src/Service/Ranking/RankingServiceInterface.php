<?php

namespace App\Service\Ranking;

use App\ApiResource\Ranking\GetRanking;
use App\Ranking\Domain\RankingType;

interface RankingServiceInterface
{
    public function getRanking(int $organizationId, string $rankingType = RankingType::FUCKED, ?int $tournamentId = null): GetRanking;
}
