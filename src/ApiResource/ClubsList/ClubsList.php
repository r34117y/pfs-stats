<?php

namespace App\ApiResource\ClubsList;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\ClubsListProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/clubs',
            description: 'Get clubs list.',
            provider: ClubsListProvider::class
        ),
    ],
)]
final readonly class ClubsList
{
    /**
     * @param ClubsListClub[] $clubs
     */
    public function __construct(
        public array $clubs,
    ) {
    }
}
