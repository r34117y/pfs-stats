<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TournamentsList\TournamentsList;
use App\Service\TournamentList\TournamentListServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class TournamentListProvider implements ProviderInterface {
    public function __construct(
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
        private TournamentListServiceInterface $tournamentListService,
    ) {
    }

    /**
     * @inheritDoc
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TournamentsList
    {
        return $this->cache->get('api.tournaments.list', function (): TournamentsList {
            return $this->tournamentListService->getTournaments();
        });
    }
}
