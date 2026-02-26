<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LongestStreakSumMin800Provider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/longest-streak-sum-min-800',
            description: 'Get longest streak of games with points sum at least 800 by player (top 1000, players with at least 30 games).',
            provider: LongestStreakSumMin800Provider::class
        ),
    ],
)]
class LongestStreakSumMin800
{
    /**
     * @param LongestStreakSumMin800Row[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
