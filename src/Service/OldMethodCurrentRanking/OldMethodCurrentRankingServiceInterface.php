<?php

namespace App\Service\OldMethodCurrentRanking;

interface OldMethodCurrentRankingServiceInterface
{
    public function calculateCurrentRanking(): array;

    public function calculateRankingSnapshots(): iterable;

    public function calculateRankingSnapshotAfterTournament(int $organizationId, int $tournamentId): ?array;
}
