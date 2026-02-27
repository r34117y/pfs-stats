<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\HighestAvgPointsDiffProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/highest-avg-points-diff',
            description: 'Get highest average points difference per game achieved by each player in tournament (top 1000, min 30 games, tournaments with >=6 rounds and >=80% participation).',
            provider: HighestAvgPointsDiffProvider::class
        ),
    ],
)]
class HighestAvgPointsDiff
{
    /**
     * @param HighestAvgPointsDiffRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
