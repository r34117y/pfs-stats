<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\LongestWinStreaks;
use App\Service\Stats\StatsServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class LongestWinStreaksProvider implements ProviderInterface
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
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LongestWinStreaks
    {
        $orgId = $uriVariables['org'] ?? 21;

        return $this->cache->get(
            sprintf('api.stats.longest_win_streaks.%s', $orgId),
            fn (): LongestWinStreaks => $this->statsService->getLongestWinStreaks($orgId),
        );
    }
}
