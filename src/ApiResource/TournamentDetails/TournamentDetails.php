<?php

namespace App\ApiResource\TournamentDetails;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\TournamentDetailsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/tournaments/{id}/details',
            description: 'Get tournament basic details.',
            provider: TournamentDetailsProvider::class
        ),
    ],
)]
class TournamentDetails
{
    public function __construct(
        public int $id,
        public string $name,
        public string $date,
        public ?string $refereeName,
        public ?string $address,
    ) {
    }
}
