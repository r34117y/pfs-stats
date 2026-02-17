<?php

namespace App\ApiResource\PlayerTournaments;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\PlayerTournamentsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/players/{id}/tournaments',
            description: 'Get tournaments list for given player ordered by most recent.',
            provider: PlayerTournamentsProvider::class
        ),
    ],
)]
class PlayerTournaments
{
    /**
     * @param PlayerTournamentsTournament[] $tournaments
     */
    public function __construct(
        public array $tournaments,
    ) {
    }
}
