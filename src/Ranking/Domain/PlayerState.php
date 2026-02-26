<?php

declare(strict_types=1);

namespace App\Ranking\Domain;

final class PlayerState
{
    public function __construct(
        public float $rating,
        public int $gamesCount = 0,
        public ?\DateTimeImmutable $lastPlayedAt = null,
    ) {
    }
}

