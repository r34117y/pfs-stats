<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayersList\PlayersList;
use App\Service\PlayerList\PlayerListServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class PlayerListProvider implements ProviderInterface {
    use ResolvesOrganizationIdFromRequestTrait;

    public function __construct(
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
        private PlayerListServiceInterface $playerListService,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayersList
    {
        $organizationId = $this->resolveOrganizationId($uriVariables, $this->requestStack);

        return $this->cache->get(sprintf('api.players.list.%d', $organizationId), function (ItemInterface $item) use ($organizationId): PlayersList {
            $item->expiresAfter(600);
            return $this->playerListService->getPlayers($organizationId);
        });
    }
}
