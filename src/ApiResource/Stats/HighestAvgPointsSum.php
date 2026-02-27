<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\HighestAvgPointsSumProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/highest-avg-points-sum',
            description: 'Get highest average points sum per game achieved by each player in tournament (top 1000, min 30 games, tournaments with >=6 rounds and >=80% participation).',
            provider: HighestAvgPointsSumProvider::class
        ),
    ],
)]
class HighestAvgPointsSum
{
    /**
     * @param HighestAvgPointsSumRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
