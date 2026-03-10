<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\TournamentsList\TournamentsList;

interface TournamentListProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TournamentsList;
}
