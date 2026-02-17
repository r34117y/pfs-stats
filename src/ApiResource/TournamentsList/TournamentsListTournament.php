<?php

namespace App\ApiResource\TournamentsList;

class TournamentsListTournament
{
    public function __construct(
        public int $id,
        public string $name,
        public string $startDate,
        public float $tournamentRank,
        public int $numberOfPlayers,
        public string $winnerName,
        public int $winnerId,
    ) {
    }
}
