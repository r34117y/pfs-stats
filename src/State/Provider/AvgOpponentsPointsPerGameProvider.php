<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\AvgOpponentsPointsPerGame;
use App\Service\Stats\StatsServiceInterface;
use DateTimeImmutable;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class AvgOpponentsPointsPerGameProvider implements ProviderInterface
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
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AvgOpponentsPointsPerGame
    {
        $orgId = $uriVariables['org'] ?? 21;
        return $this->cache->get(
            sprintf('api.stats.avg_opponents_points.%d', $orgId),
            fn (): AvgOpponentsPointsPerGame => $this->statsService->getAvgOpponentsPointsPerGame($orgId),
        );
    }
}
