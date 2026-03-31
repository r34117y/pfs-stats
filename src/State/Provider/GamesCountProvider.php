<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\GamesCount;
use App\Service\Stats\StatsServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class GamesCountProvider implements ProviderInterface
{
    public function __construct(
        private StatsServiceInterface $statsService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GamesCount
    {
        $orgId = $uriVariables['org'] ?? 21;
        return $this->cache->get(
            sprintf('api.stats.games_count.%d', $orgId),
            fn (): GamesCount => $this->statsService->getGamesCount($orgId),
        );
    }
}
