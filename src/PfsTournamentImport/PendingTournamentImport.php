<?php

namespace App\PfsTournamentImport;

final readonly class PendingTournamentImport
{
    public function __construct(
        public int $inferredId,
        public int $urlId,
        public string $name,
        public \DateTimeImmutable $endDate,
        public ParsedTournamentResults $results,
    ) {
    }

    public function getResultsUrl(): string
    {
        return sprintf('https://www.pfs.org.pl/turniej.php?id=%d#hh', $this->urlId);
    }
}
