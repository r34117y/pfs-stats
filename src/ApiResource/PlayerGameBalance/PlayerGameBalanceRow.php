<?php

namespace App\ApiResource\PlayerGameBalance;

class PlayerGameBalanceRow
{
    public function __construct(
        public int $position,
        public int $opponentId,
        public string $opponent,
        public float $winPercent,
        public int $gameBalance,
        public int $smallPointsBalance,
        public int $wins,
        public int $draws,
        public int $losses,
        public string $streak,
        public float $averagePoints,
        public float $averageOpponentPoints,
        public int $totalGames,
    ) {
    }
}
