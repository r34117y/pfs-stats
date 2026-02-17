<?php

namespace App\ApiResource\Stats;

class AllTimesResultsPlayer
{
    public function __construct(
        public int $position,
        public int $playerId,
        public string $playerName,
        public int $first,
        public int $second,
        public int $third,
        public int $fourth,
        public int $fifth,
        public int $sixth,
        public int $oneToThree,
        public int $oneToSix,
    ) {
    }
}
