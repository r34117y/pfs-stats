<?php

namespace App\Service\RankingSnapshot;

interface RankingSnapshotServiceInterface
{
    public function getRankingAfterTournament(int $tournamentId): array;
}
