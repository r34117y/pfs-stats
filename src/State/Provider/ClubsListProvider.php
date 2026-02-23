<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ClubsList\ClubsList;
use App\Service\ClubsService;

final readonly class ClubsListProvider implements ProviderInterface
{
    public function __construct(
        private ClubsService $clubsService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ClubsList
    {
        return $this->clubsService->getClubsList();
    }
}
