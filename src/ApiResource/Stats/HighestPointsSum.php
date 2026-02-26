<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\HighestPointsSumProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/highest-points-sum',
            description: 'Get games with highest points sum (top 1000 games, sorted ascending in response).',
            provider: HighestPointsSumProvider::class
        ),
    ],
)]
class HighestPointsSum
{
    /**
     * @param HighestPointsSumRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
