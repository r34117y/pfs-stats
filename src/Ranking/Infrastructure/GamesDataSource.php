<?php

declare(strict_types=1);

namespace App\Ranking\Infrastructure;

use App\Ranking\Domain\WindowDefinition;
use DateTimeImmutable;

interface GamesDataSource
{
    /**
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
     */
    public function findDateBounds(): ?array;

    /**
     * @return iterable<GameRecord>
     */
    public function streamWindowGames(WindowDefinition $window): iterable;
}
