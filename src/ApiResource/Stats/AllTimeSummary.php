<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AllTimeSummaryProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/all-time-summary',
            description: 'Get global all-time summary and last 12 months summary.',
            provider: AllTimeSummaryProvider::class
        ),
    ],
)]
class AllTimeSummary
{
    /**
     * @param AllTimeSummaryRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
