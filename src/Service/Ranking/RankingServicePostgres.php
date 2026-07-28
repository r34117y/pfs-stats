<?php

namespace App\Service\Ranking;

use App\ApiResource\Ranking\GetRanking;
use App\ApiResource\Ranking\RankingRow;
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
    public function getRanking(int $organizationId, string $rankingType = 'f', ?int $tournamentId = null): GetRanking
    {
        $rankingTournamentId = $tournamentId ?? $this->getLatestRankingTournamentId($organizationId, $rankingType);
        if ($rankingTournamentId === null) {
            return new GetRanking([]);
        }

        $lastTournament = $this->loadTournamentMeta($organizationId, $rankingTournamentId);
        $previousTournamentId = $this->getPreviousRankingTournamentId($organizationId, $rankingTournamentId, $rankingType);
        $previousNavigationTournamentId = $this->getPreviousNavigationTournamentId($organizationId, $rankingTournamentId, $rankingType);
        $nextNavigationTournamentId = $this->getNextNavigationTournamentId($organizationId, $rankingTournamentId, $rankingType);

        $latestRanking = $this->rankingSnapshotService->getRankingAfterTournament($organizationId, $rankingTournamentId, $rankingType);
        $previousRanking = $previousTournamentId !== null
            ? $this->rankingSnapshotService->getRankingAfterTournament($organizationId, $previousTournamentId, $rankingType)
            : [];

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
            } elseif ($previousTournamentId !== null) {
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
            "SELECT MAX(tournament_id)
             FROM ranking
             WHERE organization_id = :organizationId
               AND rtype = :rankingType",
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
     * Returns the best previous comparison snapshot.
     * Prefers the latest earlier snapshot with at least one rank/position change
     * versus the latest snapshot; falls back to the immediate previous snapshot.
     * @throws Exception
     */
    private function getPreviousRankingTournamentId(int $organizationId, int $latestTournamentId, string $rankingType): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT MAX(previous.tournament_id)
             FROM (
                SELECT DISTINCT r.tournament_id
                FROM ranking r
                WHERE r.organization_id = :organizationId
                  AND r.rtype = :rankingType
                  AND r.tournament_id < :latestTournamentId
             ) previous
             WHERE EXISTS (
                SELECT 1
                FROM ranking latest
                INNER JOIN ranking prev
                    ON prev.organization_id = latest.organization_id
                   AND prev.player_id = latest.player_id
                   AND prev.rtype = :rankingType
                   AND prev.tournament_id = previous.tournament_id
                WHERE latest.organization_id = :organizationId
                  AND latest.rtype = :rankingType
                  AND latest.tournament_id = :latestTournamentId
                  AND latest.player_id IS NOT NULL
                  AND (latest.position <> prev.position OR latest.rank <> prev.rank)
             )",
            [
                'organizationId' => $organizationId,
                'latestTournamentId' => $latestTournamentId,
                'rankingType' => $rankingType,
            ]
        );

        if ($value !== false && $value !== null) {
            return (int) $value;
        }

        $fallback = $this->connection->fetchOne(
            "SELECT MAX(tournament_id)
             FROM ranking
             WHERE organization_id = :organizationId
               AND rtype = :rankingType
               AND tournament_id < :latestTournamentId",
            [
                'organizationId' => $organizationId,
                'latestTournamentId' => $latestTournamentId,
                'rankingType' => $rankingType,
            ]
        );

        if ($fallback === false || $fallback === null) {
            return null;
        }

        return (int) $fallback;
    }

    /**
     * @throws Exception
     */
    private function getPreviousNavigationTournamentId(int $organizationId, int $tournamentId, string $rankingType): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT MAX(tournament_id)
             FROM ranking
             WHERE organization_id = :organizationId
               AND rtype = :rankingType
               AND tournament_id < :tournamentId",
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
            "SELECT MIN(tournament_id)
             FROM ranking
             WHERE organization_id = :organizationId
               AND rtype = :rankingType
               AND tournament_id > :tournamentId",
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

    private function formatDecimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
