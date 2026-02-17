<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\GamesWon;
use App\Service\StatsService;

class GamesWonProvider implements ProviderInterface
{
    public function __construct(
        private StatsService $statsService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GamesWon
    {
        return $this->statsService->getGamesWon();
    }
}
