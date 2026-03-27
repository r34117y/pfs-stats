<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ranking\GetRanking;
use App\ApiResource\Ranking\RankingRow;
use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class OldRankingProvider implements ProviderInterface
{
    public function __construct(
        private OldMethodCurrentRankingServiceInterface $oldMethodCurrentRankingService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GetRanking
    {
        return $this->cache->get('api.ranking.old', function (ItemInterface $item): GetRanking {
            $result = $this->oldMethodCurrentRankingService->calculateCurrentRanking();
            $rows = $result['rows'];

            if ($rows === []) {
                return new GetRanking([], $result['referenceTournamentName'], $result['referenceTournamentId']);
            }

            $rankingRows = [];
            foreach ($rows as $row) {
                $playerId = (int) $row['playerId'];

                $rankingRows[] = new RankingRow(
                    position: (int) $row['position'],
                    nameShow: (string) $row['playerName'],
                    nameAlph: (string) $row['playerNameAlph'],
                    playerId: $playerId,
                    photo: $row['photo'],
                    rank: number_format((float) $row['rankExact'], 2, '.', ''),
                    numberOfGames: (int) $row['games'],
                    rankDelta: null,
                    positionDelta: null,
                    slug: $row['slug']
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
