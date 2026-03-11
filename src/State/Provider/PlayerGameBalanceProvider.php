<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerGameBalance\PlayerGameBalance;
use App\Service\PlayerGameBalance\PlayerGameBalanceServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class PlayerGameBalanceProvider implements ProviderInterface
{
    public function __construct(
        private PlayerGameBalanceServiceInterface $playerGameBalanceService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
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
