<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TournamentDetails\TournamentDetails;
use App\Service\TournamentDetails\TournamentDetailsServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class TournamentDetailsProvider implements ProviderInterface
{
    use ResolvesOrganizationIdFromRequestTrait;
    public function __construct(
        private TournamentDetailsServiceInterface $tournamentDetailsService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TournamentDetails
    {
        $rawTournamentId = $uriVariables['id'] ?? $uriVariables['tournamentId'] ?? null;
        $tournamentId = is_numeric($rawTournamentId) ? (int) $rawTournamentId : 0;

        if ($tournamentId <= 0) {
            throw new NotFoundHttpException('Tournament not found.');
        }

        $orgId = $this->resolveOrganizationId($uriVariables, $this->requestStack);

        return $this->cache->get(
            sprintf('api.tournament_details.%d.%d', $tournamentId, $orgId),
            fn (): TournamentDetails => $this->tournamentDetailsService->getTournamentDetails($tournamentId, $orgId),
        );
    }
}
