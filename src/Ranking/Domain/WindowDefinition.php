<?php

declare(strict_types=1);

namespace App\Ranking\Domain;

final readonly class WindowDefinition
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }
}
