<?php

declare(strict_types=1);

namespace App\Ranking\Domain;

final readonly class PlayerRatingState
{
    public function __construct(
        public float $rating,
        public float $rd,
        public float $sigma,
        public ?\DateTimeImmutable $lastPlayedAt,
    ) {
    }

    public static function initial(
        float $rating = 1500.0,
        float $rd = 350.0,
        float $sigma = 0.06,
    ): self {
        return new self($rating, $rd, $sigma, null);
    }
}
