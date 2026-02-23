<?php

namespace App\ApiResource\Ranking;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\OldRankingProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/old-rank',
            description: 'Get current ranking simulated with the old method.',
            provider: OldRankingProvider::class,
        ),
    ],
)]
final class GetOldRanking extends GetRanking
{
}
