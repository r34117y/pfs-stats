<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerRankHistory\PlayerRankHistory;
use App\Service\PlayerRankHistoryService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerRankHistoryProvider implements ProviderInterface
{
    public function __construct(
        private PlayerRankHistoryService $playerRankHistoryService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerRankHistory
    {
        $rawPlayerId = $uriVariables['id'] ?? $uriVariables['playerId'] ?? null;
        $playerId = is_numeric($rawPlayerId) ? (int) $rawPlayerId : 0;

        if ($playerId <= 0) {
            throw new NotFoundHttpException('Player not found.');
        }

        return $this->playerRankHistoryService->getRankHistory($playerId);
    }
}
