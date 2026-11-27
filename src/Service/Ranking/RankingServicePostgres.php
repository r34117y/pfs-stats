<?php

namespace App\Service\Ranking;

use App\ApiResource\Ranking\GetRanking;
use App\ApiResource\Ranking\RankingRow;
use App\Ranking\Domain\RankingType;
use App\Service\RankingSnapshot\RankingSnapshotServiceInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RankingServicePostgres implements RankingServiceInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private RankingSnapshotServiceInterface $rankingSnapshotService,
    ) {
    }

    /**
     * @throws Exception
     */
    public function getRanking(int $organizationId, string $rankingType = RankingType::FUCKED, ?int $tournamentId = null): GetRanking
    {
        $rankingTournamentId = $tournamentId ?? $this->getLatestRankingTournamentId($organizationId, $rankingType);
        if ($rankingTournamentId === null) {
            return new GetRanking([]);
        }

        $lastTournament = $this->loadTournamentMeta($organizationId, $rankingTournamentId);
        $previousTournamentId = $this->getPreviousRankingTournamentId($organizationId, $rankingTournamentId);
        $previousNavigationTournamentId = $this->getPreviousNavigationTournamentId($organizationId, $rankingTournamentId, $rankingType);
        $nextNavigationTournamentId = $this->getNextNavigationTournamentId($organizationId, $rankingTournamentId, $rankingType);

        $latestRanking = $this->rankingSnapshotService->getRankingAfterTournament($organizationId, $rankingTournamentId, $rankingType);
        $previousRanking = $previousTournamentId !== null
            ? $this->rankingSnapshotService->getRankingAfterTournament($organizationId, $previousTournamentId, $rankingType)
            : [];

        // A valid preceding snapshot can become empty after eligibility filtering.
        $comparisonAvailable = $previousTournamentId !== null
            && $previousNavigationTournamentId === $previousTournamentId;
        $comparisonTournament = $previousTournamentId !== null
            ? $this->loadTournamentMeta($organizationId, $previousTournamentId)
            : ['id' => null, 'name' => null];

        $previousRankingByPlayer = [];
        foreach ($previousRanking as $row) {
            $previousRankingByPlayer[$row['playerId']] = [
                'rank' => $row['rank'],
                'position' => $row['position'],
            ];
        }

        $rankingRows = [];

        foreach ($latestRanking as $row) {
            $playerId = $row['playerId'];
            $rankDelta = null;
            $positionDelta = null;

            if (isset($previousRankingByPlayer[$playerId])) {
                $previous = $previousRankingByPlayer[$playerId];
                $currentRank = $row['rank'];
                $currentPosition = $row['position'];
                $rankDelta = $this->formatDecimal($currentRank - $previous['rank']);
                $positionDelta = $previous['position'] - $currentPosition;
            } elseif ($comparisonAvailable) {
                $positionDelta = '+';
            }

            $rankingRows[] = new RankingRow(
                $row['position'],
                $row['nameShow'],
                $row['nameAlph'],
                $playerId,
                $row['photo'],
                $this->formatDecimal($row['rank']),
                $row['games'],
                $rankDelta,
                $positionDelta,
                $row['slug']
            );
        }

        return new GetRanking(
            $rankingRows,
            $lastTournament['name'],
            $lastTournament['id'],
            $previousNavigationTournamentId,
            $nextNavigationTournamentId,
            $comparisonTournament['id'],
            $comparisonTournament['name'],
            $comparisonAvailable,
            !array_any($latestRanking, static fn (array $row): bool => $row['games'] === null),
        );
    }

    /**
     * @return array{id:int|null, name:string|null}
     * @throws Exception
     */
    private function loadTournamentMeta(int $organizationId, int $tournamentId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, COALESCE(fullname, name) AS name
             FROM tournament
             WHERE organization_id = :organizationId
               AND id = :tournamentId
             LIMIT 1',
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
            ]
        );

        if ($row === false) {
            return ['id' => null, 'name' => null];
        }

        return [
            'id' => $row['id'] !== null ? (int) $row['id'] : null,
            'name' => is_string($row['name'] ?? null) && $row['name'] !== '' ? (string) $row['name'] : null,
        ];
    }

    /**
     * @throws Exception
     */
    private function getLatestRankingTournamentId(int $organizationId, string $rankingType): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT t.id FROM tournament t
             WHERE t.organization_id=:organizationId AND ".$this->availableRankingSql()."
             ORDER BY t.dt DESC,t.id DESC LIMIT 1",
            [
                'organizationId' => $organizationId,
                'rankingType' => $rankingType,
            ]
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Compare consecutive tournaments. A missing snapshot means unknown deltas;
     * it must never turn changes across several tournaments into a one-event change.
     */
    private function getPreviousRankingTournamentId(int $organizationId, int $latestTournamentId): ?int
    {
        $value = $this->connection->fetchOne(
            'SELECT t.id FROM tournament t
             WHERE t.organization_id=:organizationId
               AND (t.dt,t.id) < (SELECT dt,id FROM tournament WHERE id=:latestTournamentId)
             ORDER BY t.dt DESC,t.id DESC LIMIT 1',
            ['organizationId' => $organizationId, 'latestTournamentId' => $latestTournamentId],
        );
        return $value === false || $value === null ? null : (int) $value;
    }

    /**
     * @throws Exception
     */
    private function getPreviousNavigationTournamentId(int $organizationId, int $tournamentId, string $rankingType): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT t.id FROM tournament t
             WHERE t.organization_id=:organizationId
               AND (t.dt,t.id) < (SELECT dt,id FROM tournament WHERE id=:tournamentId)
               AND ".$this->availableRankingSql()."
             ORDER BY t.dt DESC,t.id DESC LIMIT 1",
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
                'rankingType' => $rankingType,
            ]
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @throws Exception
     */
    private function getNextNavigationTournamentId(int $organizationId, int $tournamentId, string $rankingType): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT t.id FROM tournament t
             WHERE t.organization_id=:organizationId
               AND (t.dt,t.id) > (SELECT dt,id FROM tournament WHERE id=:tournamentId)
               AND ".$this->availableRankingSql()."
             ORDER BY t.dt ASC,t.id ASC LIMIT 1",
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
                'rankingType' => $rankingType,
            ]
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    private function availableRankingSql(): string
    {
        return "(EXISTS (SELECT 1 FROM ranking r WHERE r.tournament_id=t.id AND r.organization_id=t.organization_id AND r.rtype=:rankingType)
            OR (:rankingType='f' AND EXISTS (
                SELECT 1 FROM text_resource c WHERE c.organization_id=t.organization_id AND c.resource_type=9006
                  AND c.data::jsonb->>'tournamentDbId'=t.id::text
            )))";
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
