<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class MinGamesGridPoint
{
    public function __construct(
        public int $nMin,
        public float $falseLeaderRate,
        public float $p95WindowFalseRate,
        public float $windowRateMean,
        public float $windowRateMedian,
        public float $windowRateP90,
        public float $coverage,
        public float $excluded,
        public ?float $persistenceP90Days,
        public int $eligibleCount,
        public int $falseLeaderCount,
    ) {
    }
}

