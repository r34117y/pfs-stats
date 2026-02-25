<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TournamentDetails\TournamentResults;
use App\Service\TournamentDetailsService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

class TournamentResultsProvider implements ProviderInterface
{
    public function __construct(
        private TournamentDetailsService $tournamentDetailsService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TournamentResults
    {
        $rawTournamentId = $uriVariables['id'] ?? $uriVariables['tournamentId'] ?? null;
        $tournamentId = is_numeric($rawTournamentId) ? (int) $rawTournamentId : 0;

        if ($tournamentId <= 0) {
            throw new NotFoundHttpException('Tournament not found.');
        }

        return $this->cache->get(
            sprintf('api.tournament_results.%d', $tournamentId),
            fn (): TournamentResults => $this->tournamentDetailsService->getTournamentResults($tournamentId),
        );
    }
}
