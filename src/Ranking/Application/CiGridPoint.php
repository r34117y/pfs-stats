<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class CiGridPoint
{
    public function __construct(
        public float $ciLevel,
        public float $wMax,
        public float $inflatedProbability,
        public float $p95WindowInflatedProbability,
        public float $coverage,
        public ?float $medianGamesToQualify,
        public ?float $p90GamesToQualify,
        public int $eligibleCount,
        public int $inflatedCount,
    ) {
    }
}

