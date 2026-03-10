<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ranking\GetRanking;
use App\Service\RankingServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class RankingProvider implements ProviderInterface {
    public function __construct(
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
        private RankingServiceInterface $rankingService,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GetRanking
    {
        return $this->cache->get('api.ranking.current', function (ItemInterface $item): GetRanking {
            $item->expiresAfter(600);
            return $this->rankingService->getRanking();
        });
    }
}
