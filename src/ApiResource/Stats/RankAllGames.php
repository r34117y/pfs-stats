<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\RankAllGamesProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/rank-all-games',
            description: 'Get rank from all games by player (all-time, last 24 months, last 12 months).',
            provider: RankAllGamesProvider::class
        ),
    ],
)]
class RankAllGames
{
    /**
     * @param RankAllGamesRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
