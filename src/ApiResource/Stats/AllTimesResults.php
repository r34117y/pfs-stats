<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AllTimesResultsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/all-times-results',
            description: 'Get all-time tournament places summary for players.',
            provider: AllTimesResultsProvider::class
        ),
    ],
)]
class AllTimesResults
{
    /**
     * @param AllTimesResultsPlayer[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
