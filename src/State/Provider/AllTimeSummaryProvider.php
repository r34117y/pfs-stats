<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\AllTimeSummary;
use App\Service\StatsService;

class AllTimeSummaryProvider implements ProviderInterface
{
    public function __construct(
        private StatsService $statsService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AllTimeSummary
    {
        return $this->statsService->getAllTimeSummary();
    }
}
