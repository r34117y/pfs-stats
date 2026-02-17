<?php

namespace App\ApiResource\TournamentDetails;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\TournamentResultsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/tournaments/{id}/results',
            description: 'Get tournament results table.',
            provider: TournamentResultsProvider::class
        ),
    ],
)]
class TournamentResults
{
    /**
     * @param TournamentResultRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
