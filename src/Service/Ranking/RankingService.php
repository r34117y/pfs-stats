<?php

namespace App\Service\Ranking;

use App\ApiResource\Ranking\GetRanking;
use App\ApiResource\Ranking\RankingRow;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RankingService implements RankingServiceInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
        private UserRepository $userRepository,
        private RankingSnapshotService $rankingSnapshotService,
    )
    {
    }

    /**
     * @throws Exception
     */
    public function getRanking(): GetRanking
    {
        $latestTournamentId = $this->getLatestRankingTournamentId();
        if ($latestTournamentId === null) {
            return new GetRanking([]);
        }

        $lastTournamentName = $this->loadTournamentName($latestTournamentId);
        $previousTournamentId = $this->getPreviousRankingTournamentId($latestTournamentId);

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
    private function loadTournamentName(int $tournamentId): ?string
    {
        $name = $this->connection->fetchOne(
            'SELECT COALESCE(fullname, name) FROM PFSTOURS WHERE id = :tournamentId',
            ['tournamentId' => $tournamentId]
        );

        if ($name === false || $name === null || $name === '') {
            return null;
        }

        return (string) $name;
    }

    /**
     * @throws Exception
     */
    private function getLatestRankingTournamentId(): ?int
    {
        $value = $this->connection->fetchOne("SELECT MAX(turniej) FROM PFSRANKING WHERE rtype='f'");
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
    private function getPreviousRankingTournamentId(int $latestTournamentId): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT MAX(previous.turniej)
             FROM (
                SELECT DISTINCT r.turniej
                FROM PFSRANKING r
                WHERE r.rtype = 'f' AND r.turniej < :latestTournamentId
             ) previous
             WHERE EXISTS (
                SELECT 1
                FROM PFSRANKING latest
                INNER JOIN PFSRANKING prev
                    ON prev.player = latest.player
                   AND prev.rtype = 'f'
                   AND prev.turniej = previous.turniej
                WHERE latest.rtype = 'f'
                  AND latest.turniej = :latestTournamentId
                  AND (latest.pos <> prev.pos OR latest.rank <> prev.rank)
             )",
            ['latestTournamentId' => $latestTournamentId]
        );

        if ($value !== false && $value !== null) {
            return (int) $value;
        }

        $fallback = $this->connection->fetchOne(
            "SELECT MAX(turniej)
             FROM PFSRANKING
             WHERE rtype = 'f' AND turniej < :latestTournamentId",
            ['latestTournamentId' => $latestTournamentId]
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

    private function formatDecimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
