<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\LowestTournamentRankRecordProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/lowest-tournament-rank-record',
            description: 'Get lowest tournament rank achieved by each player (top 1000, min 30 games, tournaments with >=6 rounds and >=80% participation).',
            provider: LowestTournamentRankRecordProvider::class
        ),
    ],
)]
class LowestTournamentRankRecord
{
    /**
     * @param LowestTournamentRankRecordRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
