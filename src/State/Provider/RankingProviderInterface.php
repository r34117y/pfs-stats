<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\Ranking\GetRanking;

interface RankingProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GetRanking;
}
