<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\AllTimesResults;
use App\Service\StatsService;

class AllTimesResultsProvider implements ProviderInterface
{
    public function __construct(
        private StatsService $statsService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AllTimesResults
    {
        return $this->statsService->getAllTimesResults();
    }
}
