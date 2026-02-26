<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LongestStreakMin350Provider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/longest-streak-min-350',
            description: 'Get longest streak of games with at least 350 points by player (top 1000).',
            provider: LongestStreakMin350Provider::class
        ),
    ],
)]
class LongestStreakMin350
{
    /**
     * @param LongestStreakMin350Row[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
