<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\TournamentsCount;
use App\Service\StatsService;

class TournamentsCountProvider implements ProviderInterface
{
    public function __construct(
        private StatsService $statsService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TournamentsCount
    {
        return $this->statsService->getTournamentsCount();
    }
}
