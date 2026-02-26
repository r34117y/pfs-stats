<?php

declare(strict_types=1);

namespace App\Ranking\Domain;

interface RatingModelInterface
{
    public function initialRating(): float;

    /**
     * @return array{0: float, 1: float}
     */
    public function updateRatings(float $ratingA, float $ratingB, float $scoreA): array;
}

