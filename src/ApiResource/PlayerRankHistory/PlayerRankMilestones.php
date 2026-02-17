<?php

namespace App\ApiResource\PlayerRankHistory;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\PlayerRankMilestonesProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/players/{id}/rank-history/milestones',
            description: 'Get player rank milestones.',
            provider: PlayerRankMilestonesProvider::class
        ),
    ],
)]
class PlayerRankMilestones
{
    /**
     * @param PlayerRankMilestone[] $milestones
     */
    public function __construct(
        public array $milestones,
    ) {
    }
}
