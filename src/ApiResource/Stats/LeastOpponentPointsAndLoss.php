<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LeastOpponentPointsAndLossProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/least-opponent-points-and-loss',
            description: 'Get lost games where opponent won with the lowest score (first 1000).',
            provider: LeastOpponentPointsAndLossProvider::class
        ),
    ],
)]
class LeastOpponentPointsAndLoss
{
    /**
     * @param LeastOpponentPointsAndLossRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
