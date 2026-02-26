<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class MinGamesCalibrationReport
{
    /**
     * @param list<MinGamesGridPoint> $gridPoints
     * @param list<MinGamesRecommendation> $recommendations
     * @param array<string, list<array<string, mixed>>> $worstWindowsByN
     * @param array<string, mixed> $sensitivity
     * @param array<string, mixed> $curves
     */
    public function __construct(
        public MinGamesCalibrationConfig $config,
        public int $windowCount,
        public float $durationSeconds,
        public array $gridPoints,
        public array $recommendations,
        public array $worstWindowsByN,
        public array $sensitivity,
        public array $curves,
    ) {
    }
}

