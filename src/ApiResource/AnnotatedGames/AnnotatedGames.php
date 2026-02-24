<?php

namespace App\ApiResource\AnnotatedGames;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AnnotatedGamesProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/annotated-games',
            description: 'Get annotated games list. Query params: page, playerName, tournamentName.',
            provider: AnnotatedGamesProvider::class
        ),
    ],
)]
final readonly class AnnotatedGames
{
    /**
     * @param AnnotatedGamesRow[] $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $pageSize,
        public int $totalItems,
        public int $totalPages,
    ) {
    }
}
