<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\TournamentsCountProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/tournaments',
            description: 'Get number of tournaments played by players (all-time, last 24 months, last 12 months).',
            provider: TournamentsCountProvider::class
        ),
    ],
)]
class TournamentsCount
{
    /**
     * @param TournamentsCountRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
