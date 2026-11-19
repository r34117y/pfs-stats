<?php

declare (strict_types=1);
namespace App\PfsTournamentImport;

final readonly class PfsTournamentPreflightResult
{
    public function __construct(public int $organizationId, public ?int $existingTournamentId, public array $confirmedDebutants = [])
    {
    }
}
