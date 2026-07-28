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

final readonly class OldRankingProvider implements ProviderInterface
{
    use ResolvesOrganizationIdFromRequestTrait;
    use ProvidesCachedRankingTrait;

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
        $requestedTournamentId = $this->requestStack->getCurrentRequest()?->query->get('tournamentId');
        $tournamentId = is_numeric($requestedTournamentId) ? (int) $requestedTournamentId : null;

        return $this->provideCachedRanking(
            $this->cache,
            $this->rankingService,
            $organizationId,
            $tournamentId === null
                ? sprintf('api.ranking.old.v2.%d', $organizationId)
                : sprintf('api.ranking.old.v2.%d.tournament.%d', $organizationId, $tournamentId),
            'n',
            $tournamentId,
        );
    }
}
