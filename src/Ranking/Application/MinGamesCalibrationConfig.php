<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class MinGamesCalibrationConfig
{
    /**
     * @param list<int> $nGrid
     * @param list<float> $alphas
     * @param list<int> $nEarlyCheckpoints
     */
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public \DateInterval $step,
        public \DateInterval $window,
        public string $model,
        public float $eloK,
        public int $topK,
        public array $nGrid,
        public array $alphas,
        public array $nEarlyCheckpoints,
        public int $stableGames,
        public int $minStableGames,
        public int $deltaRank,
        public float $deltaRating,
        public int $minGamesForPlayer,
        public int $seed,
        public string $outDir,
        public array $formats,
        public int $maxWindows = 0,
        public int $persistStepGames = 5,
        public ?float $alphaWindow = null,
    ) {
    }
}

