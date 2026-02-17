<?php

namespace App\ApiResource\ClubsList;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\ApiResource\PlayersList\PlayersListPlayer;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/clubs',
            description: 'Get clubs list.',
            provider: ClubsListProvider::class
        ),
    ],
)]
class ClubsList
{

    /**
     * @param ClubsListClub[] $clubs
     */
    public function __construct(
        public array $clubs,
    ) {
    }
}
