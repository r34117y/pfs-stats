<?php

declare(strict_types=1);

namespace App\Ranking\Domain;

final readonly class SkillEstimate
{
    public function __construct(
        public int $playerId,
        public float $sHat,
        public float $se,
        public float $ciLow,
        public float $ciHigh,
        public float $ciWidth,
        public int $gamesCount,
    ) {
    }
}

