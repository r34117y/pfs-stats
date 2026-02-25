<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\TournamentsCount;
use App\Service\StatsService;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

class TournamentsCountProvider implements ProviderInterface
{
    public function __construct(
        private StatsService $statsService,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TournamentsCount
    {
        $todayKey = (new DateTimeImmutable('today'))->format('Ymd');

        return $this->cache->get(
            sprintf('api.stats.tournaments_count.%s', $todayKey),
            fn (): TournamentsCount => $this->statsService->getTournamentsCount(),
        );
    }
}
