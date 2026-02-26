<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LowestPointsSumProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/lowest-points-sum',
            description: 'Get games with lowest points sum (first 1000 games).',
            provider: LowestPointsSumProvider::class
        ),
    ],
)]
class LowestPointsSum
{
    /**
     * @param LowestPointsSumRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
