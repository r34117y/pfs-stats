<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TournamentDetails\TournamentResults;
use App\Service\TournamentDetailsService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TournamentResultsProvider implements ProviderInterface
{
    public function __construct(
        private TournamentDetailsService $tournamentDetailsService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TournamentResults
    {
        $rawTournamentId = $uriVariables['id'] ?? $uriVariables['tournamentId'] ?? null;
        $tournamentId = is_numeric($rawTournamentId) ? (int) $rawTournamentId : 0;

        if ($tournamentId <= 0) {
            throw new NotFoundHttpException('Tournament not found.');
        }

        return $this->tournamentDetailsService->getTournamentResults($tournamentId);
    }
}
