<?php

namespace App\Service\PlayerRankHistory;

use App\ApiResource\PlayerRankHistory\PlayerRankHistory;
use App\ApiResource\PlayerRankHistory\PlayerRankMilestones;

interface PlayerRankHistoryServiceInterface
{
    public function getRankHistory(int $playerId): PlayerRankHistory;
    public function getRankMilestones(int $playerId): PlayerRankMilestones;
}
