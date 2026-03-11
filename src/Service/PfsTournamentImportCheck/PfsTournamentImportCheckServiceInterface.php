<?php

namespace App\Service\PfsTournamentImportCheck;

use App\PfsTournamentImport\TournamentImportCheckResult;

interface PfsTournamentImportCheckServiceInterface
{
    public function check(int $year, ?\DateTimeImmutable $today = null): TournamentImportCheckResult;
}
