<?php

namespace App\ApiResource\Ranking;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\RankingProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/ranking',
            description: 'Get current ranking.',
            provider: RankingProvider::class
        ),
    ],
)]
class GetRanking
{
    /**
     * @param RankingRow[] $rows
     */
    public function __construct(
        public array $rows,
        public ?string $lastTournamentName = null,
        public ?int $lastTournamentId = null,
        public ?int $previousTournamentId = null,
        public ?int $nextTournamentId = null,
    ) {
    }
}
