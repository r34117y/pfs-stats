<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TournamentsList\TournamentsList;
use App\Service\TournamentList\TournamentListServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class TournamentListProvider implements ProviderInterface {
    use ResolvesOrganizationIdFromRequestTrait;

    public function __construct(
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
        private TournamentListServiceInterface $tournamentListService,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @inheritDoc
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TournamentsList
    {
        $organizationId = $this->resolveOrganizationId($uriVariables, $this->requestStack);

        return $this->cache->get(sprintf('api.tournaments.list.%d', $organizationId), function (ItemInterface $item) use ($organizationId): TournamentsList {
            $item->expiresAfter(600);
            return $this->tournamentListService->getTournaments($organizationId);
        });
    }
}
