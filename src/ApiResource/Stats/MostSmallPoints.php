<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\MostSmallPointsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/most-small-points',
            description: 'Get games ordered by highest player score (all-time).',
            provider: MostSmallPointsProvider::class
        ),
    ],
)]
class MostSmallPoints
{
    /**
     * @param MostSmallPointsRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
