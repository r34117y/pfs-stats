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
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class AvgPointsPerGameProvider implements ProviderInterface
{
    use ResolvesOrganizationIdFromRequestTrait;
    public function __construct(
        private StatsServiceInterface $statsService,
        private RequestStack $requestStack,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AvgPointsPerGame
    {
        $orgId = $this->resolveOrganizationId($uriVariables, $this->requestStack);
        return $this->cache->get(
            sprintf('api.stats.avg_points_per_game.%d', $orgId),
            fn (): AvgPointsPerGame => $this->statsService->getAvgPointsPerGame($orgId),
        );
    }
}
