<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ranking\GetRanking;
use App\ApiResource\Ranking\RankingRow;
use App\Repository\UserRepository;
use App\Service\RankingSnapshotService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class RankingProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
        private UserRepository $userRepository,
        private RankingSnapshotService $rankingSnapshotService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws Exception
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GetRanking
    {
        return $this->cache->get('api.ranking.current', function (ItemInterface $item): GetRanking {
            $item->expiresAfter(600);

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
                    $rankDelta = round($currentRank - $previous['rank'], 2);
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
                    $row['rank'],
                    $row['games'],
                    $rankDelta,
                    $positionDelta
                );
            }

            return new GetRanking($rankingRows, $lastTournamentName, $latestTournamentId);
        });
    }

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

    private function getLatestRankingTournamentId(): ?int
    {
        $value = $this->connection->fetchOne("SELECT MAX(turniej) FROM PFSRANKING WHERE rtype='f'");
        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Returns the tournament id that precedes the latest ranking snapshot.
     */
    private function getPreviousRankingTournamentId(int $latestTournamentId): ?int
    {
        $value = $this->connection->fetchOne(
            "SELECT MAX(turniej)
             FROM PFSRANKING
             WHERE rtype = 'f' AND turniej < :latestTournamentId",
            ['latestTournamentId' => $latestTournamentId]
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
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
}
