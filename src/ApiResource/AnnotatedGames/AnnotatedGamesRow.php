<?php

namespace App\ApiResource\AnnotatedGames;

final readonly class AnnotatedGamesRow
{
    public function __construct(
        public string $tournamentName,
        public int $round,
        public string $player1Name,
        public string $player2Name,
    ) {
    }
}
