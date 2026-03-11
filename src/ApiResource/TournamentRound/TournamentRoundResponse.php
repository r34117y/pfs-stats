<?php

namespace App\ApiResource\TournamentRound;

final readonly class TournamentRoundResponse
{
    public function __construct(
        public string $message,
    ) {
    }
}
