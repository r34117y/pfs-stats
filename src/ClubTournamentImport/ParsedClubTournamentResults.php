<?php

declare(strict_types=1);

namespace App\ClubTournamentImport;

use DateTimeImmutable;

final readonly class ParsedClubTournamentResults
{
    /**
     * @param list<ParsedClubPlayer> $players
     */
    public function __construct(
        public string $name,
        public DateTimeImmutable $date,
        public array $players,
    ) {
    }

    public function getDateCode(): int
    {
        return (int) $this->date->format('Ymd');
    }
}
