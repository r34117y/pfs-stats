<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\AvgPointsSumPerGame;
use App\Service\Stats\StatsServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class AvgPointsSumPerGameProvider implements ProviderInterface
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
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AvgPointsSumPerGame
    {
        $orgId = $uriVariables['org'] ?? 21;

        return $this->cache->get(
            sprintf('api.stats.avg_points_sum.%d', $orgId),
            fn (): AvgPointsSumPerGame => $this->statsService->getAvgPointsSumPerGame($orgId),
        );
    }
}
