<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\AvgPointsPerGame;
use App\Service\StatsService;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

class AvgPointsPerGameProvider implements ProviderInterface
{
    public function __construct(
        private StatsService $statsService,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AvgPointsPerGame
    {
        $todayKey = (new DateTimeImmutable('today'))->format('Ymd');

        return $this->cache->get(
            sprintf('api.stats.avg_points_per_game.%s', $todayKey),
            fn (): AvgPointsPerGame => $this->statsService->getAvgPointsPerGame(),
        );
    }
}
