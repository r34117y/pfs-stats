<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LongestLossStreaksProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/longest-loss-streaks',
            description: 'Get longest loss streak by player (top 1000).',
            provider: LongestLossStreaksProvider::class
        ),
    ],
)]
class LongestLossStreaks
{
    /**
     * @param LongestLossStreaksRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
