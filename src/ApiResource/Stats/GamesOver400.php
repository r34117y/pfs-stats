<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\GamesOver400Provider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/games-over-400',
            description: 'Get games over 400 points by player (all-time, last 24 months, last 12 months).',
            provider: GamesOver400Provider::class
        ),
    ],
)]
class GamesOver400
{
    /**
     * @param GamesOver400Row[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
