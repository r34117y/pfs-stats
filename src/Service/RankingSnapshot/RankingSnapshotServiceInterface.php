<?php

namespace App\Service\RankingSnapshot;

use App\Ranking\Domain\RankingType;

interface RankingSnapshotServiceInterface
{
    public function getRankingAfterTournament(int $organizationId, int $tournamentId, string $rankingType = RankingType::FUCKED): array;
}
