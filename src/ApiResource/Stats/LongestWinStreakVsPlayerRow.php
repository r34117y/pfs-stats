<?php

namespace App\ApiResource\Stats;

class LongestWinStreakVsPlayerRow
{
    public function __construct(
        public int $position,
        public int $winnerId,
        public string $winnerName,
        public int $opponentId,
        public string $opponentName,
        public int $winsStreak,
        public int $firstTournamentId,
        public string $firstTournamentName,
        public int $lastTournamentId,
        public string $lastTournamentName,
    ) {
    }
}
