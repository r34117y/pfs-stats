<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class MinGamesRecommendation
{
    public function __construct(
        public float $alpha,
        public float $alphaWindow,
        public int $recommendedNMin,
        public ?MinGamesGridPoint $metric,
    ) {
    }
}

