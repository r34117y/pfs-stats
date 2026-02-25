<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ranking\GetRanking;
use App\ApiResource\Ranking\RankingRow;
use App\Repository\UserRepository;
use App\Service\OldMethodCurrentRankingService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class OldRankingProvider implements ProviderInterface
{
    public function __construct(
        private OldMethodCurrentRankingService $oldMethodCurrentRankingService,
        private UserRepository $userRepository,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GetRanking
    {
        return $this->cache->get('api.ranking.old', function (ItemInterface $item): GetRanking {
            $item->expiresAfter(600);

            $result = $this->oldMethodCurrentRankingService->calculateCurrentRanking();
            $rows = $result['rows'];

            if ($rows === []) {
                return new GetRanking([], $result['referenceTournamentName'], $result['referenceTournamentId']);
            }

            $playerIds = [];
            foreach ($rows as $row) {
                $playerIds[] = (int) $row['playerId'];
            }

            $photosByPlayerId = [];
            $users = $this->userRepository->findBy(['playerId' => array_values(array_unique($playerIds))]);
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

            $rankingRows = [];
            foreach ($rows as $row) {
                $playerId = (int) $row['playerId'];

                $rankingRows[] = new RankingRow(
                    position: (int) $row['position'],
                    nameShow: (string) $row['playerName'],
                    nameAlph: (string) $row['playerNameAlph'],
                    playerId: $playerId,
                    photo: $photosByPlayerId[$playerId] ?? null,
                    rank: round($row['rankExact'], 2),
                    numberOfGames: (int) $row['games'],
                    rankDelta: null,
                    positionDelta: null,
                );
            }

            return new GetRanking(
                rows: $rankingRows,
                lastTournamentName: $result['referenceTournamentName'],
                lastTournamentId: $result['referenceTournamentId'],
            );
        });
    }
}
