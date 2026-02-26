<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LongestWinStreaksProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/longest-win-streaks',
            description: 'Get longest win streak by player (top 1000).',
            provider: LongestWinStreaksProvider::class
        ),
    ],
)]
class LongestWinStreaks
{
    /**
     * @param LongestWinStreaksRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
