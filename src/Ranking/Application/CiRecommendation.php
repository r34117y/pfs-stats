<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class CiRecommendation
{
    public function __construct(
        public float $alpha,
        public float $ciLevel,
        public float $recommendedWMax,
        public ?CiGridPoint $metric,
    ) {
    }
}

