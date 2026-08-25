<?php

namespace App\Service\OldMethodCurrentRanking;

interface OldMethodCurrentRankingServiceInterface
{
    public function calculateCurrentRanking(): array;

    /**
     * @return iterable<array{
     *   organizationId:int,
     *   referenceTournamentId:int,
     *   rows:list<array<string, mixed>>,
     *   waitingRows?:list<array<string, mixed>>
     * }>
     */
    public function calculateRankingSnapshots(): iterable;

    /**
     * @return array{
     *   organizationId?:int,
     *   referenceTournamentId?:int,
     *   rows:list<array<string, mixed>>,
     *   waitingRows?:list<array<string, mixed>>
     * }|null
     */
    public function calculateRankingSnapshotAfterTournament(int $organizationId, int $tournamentId): ?array;
}
