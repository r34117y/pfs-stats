<?php

namespace App\ApiResource\Stats;

class AllTimeSummaryRow
{
    public function __construct(
        public string $statisticName,
        public string $allTimesValue,
        public string $last12MonthsValue,
    ) {
    }
}
