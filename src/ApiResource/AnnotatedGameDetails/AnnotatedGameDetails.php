<?php

namespace App\ApiResource\AnnotatedGameDetails;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\GcgParser\ParsedGcg\ParsedGcg;
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
        public string $tournamentName,
        public int $round,
        public int $player1Id,
        public string $player1Name,
        public int $player2Id,
        public string $player2Name,
        public string $data,
        public string $updated,
        public ParsedGcg $parsedGcg,
    ) {
    }
}
