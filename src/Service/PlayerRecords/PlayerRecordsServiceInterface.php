<?php

namespace App\Service\PlayerRecords;

use App\ApiResource\PlayerRecords\PlayerRecordsTable;

interface PlayerRecordsServiceInterface
{
    public function getRecords(int $playerId, string $recordType, int $limit = 10, ?int $min = null): PlayerRecordsTable;
}
