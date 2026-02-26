<?php

declare(strict_types=1);

namespace App\Ranking\Infrastructure;

final readonly class GameRecord
{
    public function __construct(
        public int $tournamentId,
        public \DateTimeImmutable $playedAt,
        public int $roundNo,
        public int $player1Id,
        public int $player2Id,
        public int $result1,
        public int $result2,
    ) {
    }
}
