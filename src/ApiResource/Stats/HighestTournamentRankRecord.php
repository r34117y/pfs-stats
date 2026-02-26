<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\HighestTournamentRankRecordProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/highest-tournament-rank-record',
            description: 'Get highest tournament rank achieved by each player (top 1000, min 30 games, tournaments with >=7 rounds and >=80% participation).',
            provider: HighestTournamentRankRecordProvider::class
        ),
    ],
)]
class HighestTournamentRankRecord
{
    /**
     * @param HighestTournamentRankRecordRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
