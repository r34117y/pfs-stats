<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\GamesWonProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/games-won',
            description: 'Get number of games won by players (all-time, last 24 months, last 12 months).',
            provider: GamesWonProvider::class
        ),
    ],
)]
class GamesWon
{
    /**
     * @param GamesWonRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
