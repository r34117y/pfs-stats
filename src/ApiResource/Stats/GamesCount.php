<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\GamesCountProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/games',
            description: 'Get number of games played by players (all-time, last 24 months, last 12 months).',
            provider: GamesCountProvider::class
        ),
    ],
)]
class GamesCount
{
    /**
     * @param GamesCountRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
