<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerGameBalance\PlayerGameBalance;
use App\Service\PlayerGameBalance\PlayerGameBalanceServiceInterface;
use App\Service\PlayerSlugResolver;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class PlayerGameBalanceProvider implements ProviderInterface
{
    public function __construct(
        private PlayerGameBalanceServiceInterface $playerGameBalanceService,
        private PlayerSlugResolver $playerSlugResolver,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerGameBalance
    {
        $playerSlug = trim((string) ($uriVariables['slug'] ?? $uriVariables['playerSlug'] ?? ''));
        $playerId = $this->playerSlugResolver->resolveLegacyPlayerId($playerSlug);

        if ($playerSlug === '' || $playerId === null) {
            throw new NotFoundHttpException('Player not found.');
        }

        return $this->cache->get(
            sprintf('api.player_game_balance.%s', $playerSlug),
            fn (): PlayerGameBalance => $this->playerGameBalanceService->getGameBalance($playerId),
        );
    }
}
