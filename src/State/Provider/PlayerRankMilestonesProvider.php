<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerRankHistory\PlayerRankMilestones;
use App\Service\PlayerRankHistory\PlayerRankHistoryServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class PlayerRankMilestonesProvider implements ProviderInterface
{
    public function __construct(
        private PlayerRankHistoryServiceInterface $playerRankHistoryService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
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
