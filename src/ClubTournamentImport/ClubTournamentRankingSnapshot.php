<?php

declare(strict_types=1);

namespace App\ClubTournamentImport;

final readonly class ClubTournamentRankingSnapshot
{
    /** @param list<array{position:int,name:string,rank:float,games:?int}> $rows */
    public function __construct(
        public int $dateCode,
        public string $sourceUrl,
        public string $html,
        public array $rows,
    ) {
    }

    public function hasGameCounts(): bool
    {
        foreach ($this->rows as $row) {
            if ($row['games'] === null) return false;
        }
        return true;
    }
}
