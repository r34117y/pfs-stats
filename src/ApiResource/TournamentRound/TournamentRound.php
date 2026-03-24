<?php

namespace App\ApiResource\TournamentRound;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\TournamentRoundProcessor;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/tournament',
            inputFormats: ['json' => ['application/json'], 'jsonld' => ['application/ld+json']],
            description: 'Tournament round callback endpoint protected by token authorization.',
            output: TournamentRoundResponse::class,
            read: false,
            processor: TournamentRoundProcessor::class
        ),
    ],
)]
final class TournamentRound
{
    /**
     * @param array<string, mixed> $tournament
     * @param list<array<string, mixed>> $players
     * @param list<array<string, mixed>> $results
     * @param mixed $rankDay
     * @param list<array<string, mixed>> $ranking
     */
    public function __construct(
        public string $token = '',
        public array $tournament = [],
        public array $players = [],
        public array $results = [],
        public mixed $rankDay = null,
        public array $ranking = [],
    ) {
    }
}
