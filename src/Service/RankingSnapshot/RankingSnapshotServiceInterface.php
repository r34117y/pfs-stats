<?php

namespace App\Service\RankingSnapshot;

interface RankingSnapshotServiceInterface
{
    public function getRankingAfterTournament(int $organizationId, int $tournamentId, string $rankingType = 'f'): array;
}
