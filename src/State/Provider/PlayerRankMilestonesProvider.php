<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerRankHistory\PlayerRankMilestones;
use App\Service\PlayerRankHistoryService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

class PlayerRankMilestonesProvider implements ProviderInterface
{
    public function __construct(
        private PlayerRankHistoryService $playerRankHistoryService,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerRankMilestones
    {
        $rawPlayerId = $uriVariables['id'] ?? $uriVariables['playerId'] ?? null;
        $playerId = is_numeric($rawPlayerId) ? (int) $rawPlayerId : 0;

        if ($playerId <= 0) {
            throw new NotFoundHttpException('Player not found.');
        }

        return $this->cache->get(
            sprintf('api.player_rank_milestones.%d', $playerId),
            fn (): PlayerRankMilestones => $this->playerRankHistoryService->getRankMilestones($playerId),
        );
    }
}
