<?php

namespace App\ApiResource\TournamentRound;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\TournamentRoundProcessor;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/tournament/round',
            inputFormats: ['json' => ['application/json'], 'jsonld' => ['application/ld+json']],
            description: 'Dummy tournament round callback endpoint protected by token authorization.',
            output: TournamentRoundResponse::class,
            read: false,
            processor: TournamentRoundProcessor::class
        ),
    ],
)]
final class TournamentRound
{
    public function __construct(
        public string $token = '',
    ) {
    }
}
