<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\DifferentOpponentsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/different-opponents',
            description: 'Get number of different opponents by player (all-time, last 24 months, last 12 months).',
            provider: DifferentOpponentsProvider::class
        ),
    ],
)]
class DifferentOpponents
{
    /**
     * @param DifferentOpponentsRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
