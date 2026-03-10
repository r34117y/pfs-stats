<?php

namespace App\Service;

use App\PfsTournamentImport\PfsTournamentImportComparison;
use App\PfsTournamentImport\PfsTournamentImportPlan;

interface PfsTournamentImportComparerInterface
{
    public function compare(PfsTournamentImportPlan $plan): PfsTournamentImportComparison;
}
