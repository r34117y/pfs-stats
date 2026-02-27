<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LowestAvgSmallPointsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/lowest-avg-small-points',
            description: 'Get lowest average small points per game achieved by each player in tournament (top 1000, min 30 games, tournaments with >=6 rounds and >=80% participation).',
            provider: LowestAvgSmallPointsProvider::class
        ),
    ],
)]
class LowestAvgSmallPoints
{
    /**
     * @param LowestAvgSmallPointsRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
