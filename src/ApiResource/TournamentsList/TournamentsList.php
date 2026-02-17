<?php

namespace App\ApiResource\TournamentsList;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\TournamentListProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/tournaments',
            description: 'Get tournaments list from the most recent.',
            provider: TournamentListProvider::class
        ),
    ],
)]
class TournamentsList
{
    /**
     * @param TournamentsListTournament[] $tournaments
     */
    public function __construct(
        public array $tournaments,
    ) {
    }
}
