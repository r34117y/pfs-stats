<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\HighestRankPositionProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/highest-rank-position',
            description: 'Get highest rank position achieved by player (all-time, last 24 months, last 12 months).',
            provider: HighestRankPositionProvider::class
        ),
    ],
)]
class HighestRankPosition
{
    /**
     * @param HighestRankPositionRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
