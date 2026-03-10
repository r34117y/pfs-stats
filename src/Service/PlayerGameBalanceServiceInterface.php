<?php

namespace App\Service;

use App\ApiResource\PlayerGameBalance\PlayerGameBalance;

interface PlayerGameBalanceServiceInterface
{
    public function getGameBalance(int $playerId): PlayerGameBalance;
}
