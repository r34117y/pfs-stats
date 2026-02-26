<?php

declare(strict_types=1);

namespace App\Ranking\Domain;

final readonly class EloRatingModel implements RatingModelInterface
{
    public function __construct(
        private float $kFactor = 20.0,
        private float $initial = 0.0,
    ) {
    }

    public function initialRating(): float
    {
        return $this->initial;
    }

    public function updateRatings(float $ratingA, float $ratingB, float $scoreA): array
    {
        $expectedA = 1.0 / (1.0 + 10.0 ** (($ratingB - $ratingA) / 400.0));
        $expectedB = 1.0 - $expectedA;
        $scoreB = 1.0 - $scoreA;

        return [
            $ratingA + $this->kFactor * ($scoreA - $expectedA),
            $ratingB + $this->kFactor * ($scoreB - $expectedB),
        ];
    }
}

