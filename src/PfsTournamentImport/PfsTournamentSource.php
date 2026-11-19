<?php

declare (strict_types=1);
namespace App\PfsTournamentImport;

final readonly class PfsTournamentSource
{
    public function __construct(public CalendarTournament $tournament, public string $path, public string $sha256, public ParsedTournamentResults $results)
    {
    }
}
