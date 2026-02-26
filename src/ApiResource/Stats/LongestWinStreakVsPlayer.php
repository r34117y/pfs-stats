<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LongestWinStreakVsPlayerProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/longest-win-streak-vs-player',
            description: 'Get longest consecutive wins against the same opponent for each player (top 1000, players with at least 30 games).',
            provider: LongestWinStreakVsPlayerProvider::class
        ),
    ],
)]
class LongestWinStreakVsPlayer
{
    /**
     * @param LongestWinStreakVsPlayerRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
