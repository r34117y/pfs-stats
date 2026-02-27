<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\YearlyAllTimesResultsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/yearly-all-times-results',
            description: 'Get yearly tournament places summary for players. Query parameter: year (defaults to previous year).',
            provider: YearlyAllTimesResultsProvider::class
        ),
    ],
)]
class YearlyAllTimesResults
{
    /**
     * @param AllTimesResultsPlayer[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
