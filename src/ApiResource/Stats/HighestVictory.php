<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\HighestVictoryProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/highest-victory',
            description: 'Get games with highest winner advantage (top 1000).',
            provider: HighestVictoryProvider::class
        ),
    ],
)]
class HighestVictory
{
    /**
     * @param HighestVictoryRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
