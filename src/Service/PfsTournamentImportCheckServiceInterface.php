<?php

namespace App\Service;

use App\PfsTournamentImport\TournamentImportCheckResult;

interface PfsTournamentImportCheckServiceInterface
{
    public function check(int $year, ?\DateTimeImmutable $today = null): TournamentImportCheckResult;
}
