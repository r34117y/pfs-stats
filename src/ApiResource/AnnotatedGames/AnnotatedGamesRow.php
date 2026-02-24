<?php

namespace App\ApiResource\AnnotatedGames;

final readonly class AnnotatedGamesRow
{
    public function __construct(
        public int $tournamentId,
        public string $tournamentName,
        public int $round,
        public int $player1Id,
        public string $player1Name,
        public int $player2Id,
        public string $player2Name,
    ) {
    }
}
