<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class CalibrationReport
{
    /**
     * @param list<CandidateMetric> $metrics
     * @param list<Recommendation> $recommendations
     * @param list<array<string, mixed>> $windowSummaries
     * @param array<string, mixed> $sensitivity
     */
    public function __construct(
        public CalibrationConfig $config,
        public int $windowCount,
        public float $durationSeconds,
        public array $metrics,
        public array $recommendations,
        public array $windowSummaries,
        public array $sensitivity,
        public array $curves,
    ) {
    }
}
