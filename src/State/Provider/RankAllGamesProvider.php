<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\RankAllGames;
use App\Service\Stats\StatsServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class RankAllGamesProvider implements ProviderInterface
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
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RankAllGames
    {
        $orgId = $this->resolveOrganizationId($uriVariables, $this->requestStack);
        return $this->cache->get(
            sprintf('api.stats.rank_all_games.%s', $orgId),
            fn (): RankAllGames => $this->statsService->getRankAllGames($orgId),
        );
    }
}
