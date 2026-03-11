<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayersList\PlayersList;
use App\Service\PlayerList\PlayerListServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class PlayerListProvider implements ProviderInterface {
    public function __construct(
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
        private PlayerListServiceInterface $playerListService,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayersList
    {
        return $this->cache->get('api.players.list', function (ItemInterface $item): PlayersList {
            $item->expiresAfter(600);
            return $this->playerListService->getPlayers();
        });
    }
}
