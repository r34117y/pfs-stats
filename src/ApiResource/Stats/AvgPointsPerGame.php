<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AvgPointsPerGameProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/avg-points-per-game',
            description: 'Get average points per game by player (all-time, last 24 months, last 12 months).',
            provider: AvgPointsPerGameProvider::class
        ),
    ],
)]
class AvgPointsPerGame
{
    /**
     * @param AvgPointsPerGameRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
