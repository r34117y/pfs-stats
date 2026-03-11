<?php

namespace App\Service;

interface RankingSnapshotServiceInterface
{
    public function getRankingAfterTournament(int $tournamentId): array;
}
