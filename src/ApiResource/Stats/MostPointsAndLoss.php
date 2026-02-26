<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\MostPointsAndLossProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/most-points-and-loss',
            description: 'Get lost games with highest losing score (top 1000).',
            provider: MostPointsAndLossProvider::class
        ),
    ],
)]
class MostPointsAndLoss
{
    /**
     * @param MostPointsAndLossRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
