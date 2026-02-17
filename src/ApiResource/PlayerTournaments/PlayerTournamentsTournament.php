<?php

namespace App\ApiResource\PlayerTournaments;

class PlayerTournamentsTournament
{
    public function __construct(
        public int $id,
        public string $name,
        public string $date,
        public float $tournamentRank,
        public int $numberOfPlayers,
        public int $finalPosition,
        public int $gamesWon,
        public int $gamesDraw,
        public int $gamesLost,
        public float $averagePoints,
        public float $averagePointsLost,
        public float $averagePointsSum,
        public float $achievedRank,
        public ?float $positionAsPercent,
    ) {
    }
}
