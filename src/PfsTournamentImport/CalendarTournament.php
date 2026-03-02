<?php

namespace App\PfsTournamentImport;

final readonly class CalendarTournament
{
    public function __construct(
        public int $urlId,
        public string $name,
        public \DateTimeImmutable $endDate,
    ) {
    }

    public function getResultsUrl(): string
    {
        return sprintf('https://www.pfs.org.pl/turniej.php?id=%d#hh', $this->urlId);
    }
}
