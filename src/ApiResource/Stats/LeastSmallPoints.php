<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LeastSmallPointsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/least-small-points',
            description: 'Get games ordered by lowest player score (per-player all-time minimum).',
            provider: LeastSmallPointsProvider::class
        ),
    ],
)]
class LeastSmallPoints
{
    /**
     * @param LeastSmallPointsRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
