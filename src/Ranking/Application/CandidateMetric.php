<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class CandidateMetric
{
    public function __construct(
        public float $rdMax,
        public int $eligibleCount,
        public int $inflatedCount,
        public float $inflatedProbability,
        public float $coverage,
        public ?float $gamesToQualifyP50,
        public ?float $gamesToQualifyP90,
    ) {
    }
}
