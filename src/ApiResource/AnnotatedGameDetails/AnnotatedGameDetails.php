<?php

namespace App\ApiResource\AnnotatedGameDetails;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AnnotatedGameDetailsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/games/{id}',
            description: 'Get annotated game details by key {tournamentId}-{round}-{player1Id}.',
            provider: AnnotatedGameDetailsProvider::class,
        ),
    ],
)]
final readonly class AnnotatedGameDetails
{
    public function __construct(
        public int $tournamentId,
        public int $round,
        public int $player1Id,
        public string $data,
        public string $updated,
    ) {
    }
}
