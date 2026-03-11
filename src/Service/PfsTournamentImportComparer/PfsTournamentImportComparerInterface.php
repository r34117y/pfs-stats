<?php

namespace App\Service\PfsTournamentImportComparer;

use App\PfsTournamentImport\PfsTournamentImportComparison;
use App\PfsTournamentImport\PfsTournamentImportPlan;

interface PfsTournamentImportComparerInterface
{
    public function compare(PfsTournamentImportPlan $plan): PfsTournamentImportComparison;
}
