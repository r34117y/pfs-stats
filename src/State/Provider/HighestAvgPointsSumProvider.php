<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\HighestAvgPointsSum;
use App\Service\StatsService;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class HighestAvgPointsSumProvider implements ProviderInterface
{
    public function __construct(
        private StatsService $statsService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HighestAvgPointsSum
    {
        $todayKey = (new DateTimeImmutable('today'))->format('Ymd');

        return $this->cache->get(
            sprintf('api.stats.highest_avg_points_sum.v1.%s', $todayKey),
            fn (): HighestAvgPointsSum => $this->statsService->getHighestAvgPointsSum(),
        );
    }
}
