<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\HighestRankPosition;
use App\Service\Stats\StatsServiceInterface;
use DateTimeImmutable;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class HighestRankPositionProvider implements ProviderInterface
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
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HighestRankPosition
    {
        $orgId = $this->resolveOrganizationId($uriVariables, $this->requestStack);

        return $this->cache->get(
            sprintf('api.stats.highest_rank_position.%s', $orgId),
            fn (): HighestRankPosition => $this->statsService->getHighestRankPosition($orgId),
        );
    }
}
