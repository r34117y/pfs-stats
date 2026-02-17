<?php

namespace App\ApiResource\PlayersList;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\PlayerListProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/players',
            description: 'Get players list.',
            provider: PlayerListProvider::class
        ),
    ],
)]
class PlayersList
{

    /**
     * @param PlayersListPlayer[] $players
     */
    public function __construct(
        public array $players,
    ) {
    }
}
