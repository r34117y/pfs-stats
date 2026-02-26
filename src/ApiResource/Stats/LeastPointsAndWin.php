<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LeastPointsAndWinProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/least-points-and-win',
            description: 'Get won games with lowest winner score (first 1000).',
            provider: LeastPointsAndWinProvider::class
        ),
    ],
)]
class LeastPointsAndWin
{
    /**
     * @param LeastPointsAndWinRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
