<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LowestAvgPointsDiffProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/lowest-avg-points-diff',
            description: 'Get lowest average points difference per game achieved by each player in tournament (top 1000, min 30 games, tournaments with >=6 rounds and >=80% participation).',
            provider: LowestAvgPointsDiffProvider::class
        ),
    ],
)]
class LowestAvgPointsDiff
{
    /**
     * @param LowestAvgPointsDiffRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
