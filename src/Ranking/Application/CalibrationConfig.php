<?php

declare(strict_types=1);

namespace App\Ranking\Application;

final readonly class CalibrationConfig
{
    /**
     * @param list<float> $alphas
     * @param list<float> $rdGrid
     */
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public \DateInterval $step,
        public \DateInterval $window,
        public int $k,
        public int $earlyGames,
        public int $stableGames,
        public array $alphas,
        public float $tau,
        public int $seed,
        public string $outDir,
        public array $rdGrid,
        public int $minGamesForPlayer,
        public int $minStableGames,
        public int $deltaRank,
        public float $deltaRating,
        public float $initialRating = 1500.0,
        public float $initialRd = 350.0,
        public float $initialSigma = 0.06,
        public float $daysPerRatingPeriod = 1.0,
        public float $rdUpperBound = 350.0,
    ) {
    }
}
