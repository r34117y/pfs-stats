<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\DifferentOpponents;
use App\Service\Stats\StatsServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DifferentOpponentsProvider implements ProviderInterface
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
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DifferentOpponents
    {
        $orgId = $this->resolveOrganizationId($uriVariables, $this->requestStack);

        return $this->cache->get(
            sprintf('api.stats.different_opponents.%s', $orgId),
            fn (): DifferentOpponents => $this->statsService->getDifferentOpponents($orgId),
        );
    }
}
