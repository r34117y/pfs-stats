<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class CiCalibrationReport
{
    /**
     * @param list<CiGridPoint> $gridPoints
     * @param list<CiRecommendation> $recommendations
     * @param array<string, list<array<string, mixed>>> $worstWindowsByRecommendation
     * @param array<string, mixed> $sensitivity
     * @param array<string, mixed> $curves
     */
    public function __construct(
        public CiCalibrationConfig $config,
        public int $windowCount,
        public float $durationSeconds,
        public array $gridPoints,
        public array $recommendations,
        public array $worstWindowsByRecommendation,
        public array $sensitivity,
        public array $curves,
    ) {
    }
}

