<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\PlayersList\PlayersList;

interface PlayerListProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayersList;
}
