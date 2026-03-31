<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\AvgPointsPerGame;
use App\Service\Stats\StatsServiceInterface;
use DateTimeImmutable;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class AvgPointsPerGameProvider implements ProviderInterface
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
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AvgPointsPerGame
    {
        $orgId = $uriVariables['org'] ?? 21;
        return $this->cache->get(
            sprintf('api.stats.avg_points_per_game.%d', $orgId),
            fn (): AvgPointsPerGame => $this->statsService->getAvgPointsPerGame($orgId),
        );
    }
}
