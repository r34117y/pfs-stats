<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LongestStreakMin400Provider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/longest-streak-min-400',
            description: 'Get longest streak of games with at least 400 points by player (top 1000).',
            provider: LongestStreakMin400Provider::class
        ),
    ],
)]
class LongestStreakMin400
{
    /**
     * @param LongestStreakMin400Row[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
