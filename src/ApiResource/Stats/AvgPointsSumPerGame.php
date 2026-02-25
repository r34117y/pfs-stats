<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AvgPointsSumPerGameProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/avg-points-sum',
            description: 'Get average sum of points by player (all-time, last 24 months, last 12 months).',
            provider: AvgPointsSumPerGameProvider::class
        ),
    ],
)]
class AvgPointsSumPerGame
{
    /**
     * @param AvgPointsSumPerGameRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
