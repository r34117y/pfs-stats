<?php

namespace App\ApiResource\PlayerRankHistory;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\PlayerRankHistoryProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/players/{id}/rank-history',
            description: 'Get player rank history for played tournaments.',
            provider: PlayerRankHistoryProvider::class
        ),
    ],
)]
class PlayerRankHistory
{
    /**
     * @param PlayerRankHistoryPoint[] $history
     */
    public function __construct(
        public array $history,
    ) {
    }
}
