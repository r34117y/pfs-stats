<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\HighestRankProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/highest-rank',
            description: 'Get highest achieved rank by player (all-time, last 24 months, last 12 months).',
            provider: HighestRankProvider::class
        ),
    ],
)]
class HighestRank
{
    /**
     * @param HighestRankRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
