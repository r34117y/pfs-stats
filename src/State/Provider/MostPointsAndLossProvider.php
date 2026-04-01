<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\MostPointsAndLoss;
use App\Service\Stats\StatsServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class MostPointsAndLossProvider implements ProviderInterface
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
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MostPointsAndLoss
    {
        $orgId = $uriVariables['org'] ?? 21;

        return $this->cache->get(
            sprintf('api.stats.most_points_and_loss.%s', $orgId),
            fn (): MostPointsAndLoss => $this->statsService->getMostPointsAndLoss($orgId),
        );
    }
}
