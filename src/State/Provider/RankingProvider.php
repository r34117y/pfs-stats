<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ranking\GetRanking;
use App\Ranking\Domain\RankingType;
use App\Service\Ranking\RankingServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class RankingProvider implements ProviderInterface {
    use ResolvesOrganizationIdFromRequestTrait;
    use ProvidesCachedRankingTrait;

    public function __construct(
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
        private RankingServiceInterface $rankingService,
        private RequestStack $requestStack,
    ) {
    }

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
                ? sprintf('api.ranking.current.v3.%d', $organizationId)
                : sprintf('api.ranking.current.v3.%d.tournament.%d', $organizationId, $tournamentId),
            RankingType::FUCKED,
            $tournamentId,
        );
    }
}
