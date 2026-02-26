<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class Recommendation
{
    public function __construct(
        public float $alpha,
        public float $recommendedRdMax,
        public ?CandidateMetric $metric,
    ) {
    }
}
