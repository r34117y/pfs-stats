<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LowestAvgPointsSumProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/lowest-avg-points-sum',
            description: 'Get lowest average points sum per game achieved by each player in tournament (top 1000, min 30 games, tournaments with >=6 rounds and >=80% participation).',
            provider: LowestAvgPointsSumProvider::class
        ),
    ],
)]
class LowestAvgPointsSum
{
    /**
     * @param LowestAvgPointsSumRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
