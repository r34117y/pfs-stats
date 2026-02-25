<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\AllTimesResults;
use App\Service\StatsService;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class AllTimesResultsProvider implements ProviderInterface
{
    public function __construct(
        private StatsService $statsService,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AllTimesResults
    {
        $todayKey = (new DateTimeImmutable('today'))->format('Ymd');

        return $this->cache->get(
            sprintf('api.stats.all_times_results.%s', $todayKey),
            fn (): AllTimesResults => $this->statsService->getAllTimesResults(),
        );
    }
}
