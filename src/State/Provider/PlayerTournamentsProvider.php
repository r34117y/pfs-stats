<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerTournaments\PlayerTournaments;
use App\Service\PlayerTournamentsService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerTournamentsProvider implements ProviderInterface
{
    public function __construct(
        private PlayerTournamentsService $playerTournamentsService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerTournaments
    {
        $rawPlayerId = $uriVariables['id'] ?? $uriVariables['playerId'] ?? null;
        $playerId = is_numeric($rawPlayerId) ? (int) $rawPlayerId : 0;

        if ($playerId <= 0) {
            throw new NotFoundHttpException('Player not found.');
        }

        return $this->playerTournamentsService->getPlayerTournaments($playerId);
    }
}
