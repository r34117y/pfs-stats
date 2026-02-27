<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\HighestAvgSmallPointsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/highest-avg-small-points',
            description: 'Get highest average small points per game achieved by each player in tournament (top 1000, min 30 games, tournaments with >=6 rounds and >=80% participation).',
            provider: HighestAvgSmallPointsProvider::class
        ),
    ],
)]
class HighestAvgSmallPoints
{
    /**
     * @param HighestAvgSmallPointsRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
