<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\AvgPointsPerGame;
use App\Service\StatsService;

class AvgPointsPerGameProvider implements ProviderInterface
{
    public function __construct(
        private StatsService $statsService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AvgPointsPerGame
    {
        return $this->statsService->getAvgPointsPerGame();
    }
}
