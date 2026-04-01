<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\DifferentOpponents;
use App\Service\Stats\StatsServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class DifferentOpponentsProvider implements ProviderInterface
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
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DifferentOpponents
    {
        $orgId = $uriVariables['org'] ?? 21;

        return $this->cache->get(
            sprintf('api.stats.different_opponents.%s', $orgId),
            fn (): DifferentOpponents => $this->statsService->getDifferentOpponents($orgId),
        );
    }
}
