<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AvgOpponentsPointsPerGameProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/avg-opponents-points',
            description: 'Get average opponents points by player (all-time, last 24 months, last 12 months).',
            provider: AvgOpponentsPointsPerGameProvider::class
        ),
    ],
)]
class AvgOpponentsPointsPerGame
{
    /**
     * @param AvgOpponentsPointsPerGameRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
