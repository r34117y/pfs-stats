<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerTournamentSummary\PlayerTournamentSummary;
use App\Service\PlayerTournamentSummaryService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerTournamentSummaryProvider implements ProviderInterface
{
    public function __construct(
        private PlayerTournamentSummaryService $playerTournamentSummaryService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerTournamentSummary
    {
        $rawTournamentId = $uriVariables['tournamentId'] ?? $uriVariables['id'] ?? null;
        $rawPlayerId = $uriVariables['playerId'] ?? null;
        $tournamentId = is_numeric($rawTournamentId) ? (int) $rawTournamentId : 0;
        $playerId = is_numeric($rawPlayerId) ? (int) $rawPlayerId : 0;

        if ($tournamentId <= 0 || $playerId <= 0) {
            throw new NotFoundHttpException('Invalid tournament or player.');
        }

        return $this->playerTournamentSummaryService->getSummary($tournamentId, $playerId);
    }
}
