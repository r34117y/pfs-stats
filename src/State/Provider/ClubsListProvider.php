<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ClubsList\ClubsList;
use App\Service\ClubsService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class ClubsListProvider implements ProviderInterface
{
    public function __construct(
        private ClubsService $clubsService,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ClubsList
    {
        return $this->cache->get(
            'api.clubs.list',
            fn (): ClubsList => $this->clubsService->getClubsList(),
        );
    }
}
