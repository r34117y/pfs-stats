<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ranking\GetRanking;
use App\Service\Ranking\RankingServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class RankingProvider implements ProviderInterface {
    use ResolvesOrganizationIdFromRequestTrait;

    public function __construct(
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
        private RankingServiceInterface $rankingService,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GetRanking
    {
        $organizationId = $this->resolveOrganizationId($uriVariables, $this->requestStack);

        return $this->cache->get(sprintf('api.ranking.current.%d', $organizationId), function (ItemInterface $item) use ($organizationId): GetRanking {
            $item->expiresAfter(600);
            return $this->rankingService->getRanking($organizationId);
        });
    }
}
