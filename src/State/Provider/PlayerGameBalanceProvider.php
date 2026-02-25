<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerGameBalance\PlayerGameBalance;
use App\Service\PlayerGameBalanceService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

class PlayerGameBalanceProvider implements ProviderInterface
{
    public function __construct(
        private PlayerGameBalanceService $playerGameBalanceService,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerGameBalance
    {
        $rawPlayerId = $uriVariables['id'] ?? $uriVariables['playerId'] ?? null;
        $playerId = is_numeric($rawPlayerId) ? (int) $rawPlayerId : 0;

        if ($playerId <= 0) {
            throw new NotFoundHttpException('Player not found.');
        }

        return $this->cache->get(
            sprintf('api.player_game_balance.%d', $playerId),
            fn (): PlayerGameBalance => $this->playerGameBalanceService->getGameBalance($playerId),
        );
    }
}
