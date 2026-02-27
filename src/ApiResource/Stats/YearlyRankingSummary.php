<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\YearlyRankingSummaryProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/yearly-ranking-summary',
            description: 'Get yearly ranking summary. Query parameter: year (defaults to previous year).',
            provider: YearlyRankingSummaryProvider::class
        ),
    ],
)]
class YearlyRankingSummary
{
    /**
     * @param YearlyRankingSummaryRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
