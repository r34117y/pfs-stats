<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\HighestDrawProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/highest-draw',
            description: 'Get drawn games with highest score (top 1000).',
            provider: HighestDrawProvider::class
        ),
    ],
)]
class HighestDraw
{
    /**
     * @param HighestDrawRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
