<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerRankHistory\PlayerRankHistory;
use App\Service\PlayerRankHistory\PlayerRankHistoryService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

class PlayerRankHistoryProvider implements ProviderInterface
{
    public function __construct(
        private PlayerRankHistoryService $playerRankHistoryService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerRankHistory
    {
        $rawPlayerId = $uriVariables['id'] ?? $uriVariables['playerId'] ?? null;
        $playerId = is_numeric($rawPlayerId) ? (int) $rawPlayerId : 0;

        if ($playerId <= 0) {
            throw new NotFoundHttpException('Player not found.');
        }

        return $this->cache->get(
            sprintf('api.player_rank_history.%d', $playerId),
            fn (): PlayerRankHistory => $this->playerRankHistoryService->getRankHistory($playerId),
        );
    }
}
