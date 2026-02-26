<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LongestStreakSumMin750Provider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/longest-streak-sum-min-750',
            description: 'Get longest streak of games with points sum at least 750 by player (top 1000, players with at least 30 games).',
            provider: LongestStreakSumMin750Provider::class
        ),
    ],
)]
class LongestStreakSumMin750
{
    /**
     * @param LongestStreakSumMin750Row[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
