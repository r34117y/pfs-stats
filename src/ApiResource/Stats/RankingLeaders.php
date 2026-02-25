<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\RankingLeadersProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/ranking-leaders',
            description: 'Get ranking leader streaks by player.',
            provider: RankingLeadersProvider::class
        ),
    ],
)]
class RankingLeaders
{
    /**
     * @param RankingLeadersRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
