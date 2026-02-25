<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AvgPointsDifferencePerGameProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/avg-points-difference',
            description: 'Get average points difference by player (all-time, last 24 months, last 12 months).',
            provider: AvgPointsDifferencePerGameProvider::class
        ),
    ],
)]
class AvgPointsDifferencePerGame
{
    /**
     * @param AvgPointsDifferencePerGameRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
