<?php

namespace App\Service;

use App\ApiResource\Ranking\GetRanking;
use App\ApiResource\Ranking\RankingRow;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RankingServicePostgres implements RankingServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private UserRepository $userRepository,
        private RankingSnapshotServicePostgres $rankingSnapshotService,
    ) {
    }

    /**
     * @throws Exception
     */
    public function getRanking(): GetRanking
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            return new GetRanking([]);
        }

        $latestTournamentId = $this->getLatestRankingTournamentId($organizationId);
        if ($latestTournamentId === null) {
            return new GetRanking([]);
        }

        $lastTournamentName = $this->loadTournamentName($organizationId, $latestTournamentId);
        $previousTournamentId = $this->getPreviousRankingTournamentId($organizationId, $latestTournamentId);

        $latestRanking = $this->rankingSnapshotService->getRankingAfterTournament($latestTournamentId);
        $previousRanking = $previousTournamentId !== null
            ? $this->rankingSnapshotService->getRankingAfterTournament($previousTournamentId)
            : [];

        $previousRankingByPlayer = [];
        foreach ($previousRanking as $row) {
            $previousRankingByPlayer[$row['playerId']] = [
                'rank' => $row['rank'],
                'position' => $row['position'],
            ];
        }

        $rankingRows = [];
        $photosByPlayerId = $this->loadPhotosByPlayerId($latestRanking);

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
                $photosByPlayerId[$playerId] ?? null,
                $this->formatDecimal($row['rank']),
                $row['games'],
                $rankDelta,
                $positionDelta
            );
        }

        return new GetRanking($rankingRows, $lastTournamentName, $latestTournamentId);
    }

    /**
     * @throws Exception
     */
    private function loadTournamentName(int $organizationId, int $tournamentId): ?string
    {
        $name = $this->connection->fetchOne(
            'SELECT COALESCE(fullname, name) FROM tournament WHERE organization_id = :organizationId AND legacy_id = :tournamentId LIMIT 1',
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
            ]
        );

        if ($name === false || $name === null || $name === '') {
            return null;
        }

        return (string) $name;
    }

    /**
     * @throws Exception
     */
    private function getLatestRankingTournamentId(int $organizationId): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT MAX(legacy_tournament_id)
             FROM ranking
             WHERE organization_id = :organizationId
               AND rtype = 'f'",
            ['organizationId' => $organizationId]
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
    private function getPreviousRankingTournamentId(int $organizationId, int $latestTournamentId): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT MAX(previous.legacy_tournament_id)
             FROM (
                SELECT DISTINCT r.legacy_tournament_id
                FROM ranking r
                WHERE r.organization_id = :organizationId
                  AND r.rtype = 'f'
                  AND r.legacy_tournament_id < :latestTournamentId
             ) previous
             WHERE EXISTS (
                SELECT 1
                FROM ranking latest
                INNER JOIN ranking prev
                    ON prev.organization_id = latest.organization_id
                   AND prev.legacy_player_id = latest.legacy_player_id
                   AND prev.rtype = 'f'
                   AND prev.legacy_tournament_id = previous.legacy_tournament_id
                WHERE latest.organization_id = :organizationId
                  AND latest.rtype = 'f'
                  AND latest.legacy_tournament_id = :latestTournamentId
                  AND latest.legacy_player_id IS NOT NULL
                  AND (latest.position <> prev.position OR latest.rank <> prev.rank)
             )",
            [
                'organizationId' => $organizationId,
                'latestTournamentId' => $latestTournamentId,
            ]
        );

        if ($value !== false && $value !== null) {
            return (int) $value;
        }

        $fallback = $this->connection->fetchOne(
            "SELECT MAX(legacy_tournament_id)
             FROM ranking
             WHERE organization_id = :organizationId
               AND rtype = 'f'
               AND legacy_tournament_id < :latestTournamentId",
            [
                'organizationId' => $organizationId,
                'latestTournamentId' => $latestTournamentId,
            ]
        );

        if ($fallback === false || $fallback === null) {
            return null;
        }

        return (int) $fallback;
    }

    /**
     * @param list<array{playerId: int, position: int, rank: float, games: int, nameShow: string, nameAlph: string}> $rankingRows
     * @return array<int, string>
     */
    private function loadPhotosByPlayerId(array $rankingRows): array
    {
        $playerIds = [];
        foreach ($rankingRows as $row) {
            $playerIds[] = $row['playerId'];
        }

        $playerIds = array_values(array_unique($playerIds));
        if ($playerIds === []) {
            return [];
        }

        $photosByPlayerId = [];
        $users = $this->userRepository->findBy(['playerId' => $playerIds]);
        foreach ($users as $user) {
            $playerId = $user->getPlayerId();
            $photo = $user->getPhoto();
            if ($playerId === null || $photo === null || $photo === '') {
                continue;
            }

            if (!isset($photosByPlayerId[$playerId])) {
                $photosByPlayerId[$playerId] = $photo;
            }
        }

        return $photosByPlayerId;
    }

    private function fetchOrganizationId(): ?int
    {
        $value = $this->connection->fetchOne(
            'SELECT id FROM organization WHERE code = :code LIMIT 1',
            ['code' => self::ORGANIZATION_CODE]
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
