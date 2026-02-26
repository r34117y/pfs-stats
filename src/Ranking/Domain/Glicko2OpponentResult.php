<?php

declare(strict_types=1);

namespace App\Ranking\Domain;

final readonly class Glicko2OpponentResult
{
    public function __construct(
        public float $rating,
        public float $rd,
        public float $score,
    ) {
    }
}
