<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class CiCalibrationConfig
{
    /**
     * @param list<int> $nEarly
     * @param list<float> $alphas
     * @param list<float> $ciLevels
     * @param list<float> $wGrid
     * @param list<string> $formats
     */
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public \DateInterval $step,
        public \DateInterval $window,
        public int $topK,
        public array $nEarly,
        public int $stableGames,
        public array $alphas,
        public array $ciLevels,
        public float $sigmaPrior,
        public int $maxIter,
        public float $tol,
        public array $wGrid,
        public int $minGamesForPlayer,
        public int $seed,
        public string $outDir,
        public array $formats,
        public int $maxWindows = 0,
        public int $deltaRank = 30,
        public float $deltaSkill = 0.0,
        public int $maxFullCovariancePlayers = 120,
    ) {
    }
}

