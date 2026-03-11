<?php

namespace App\Service\PlayerGameBalance;

use App\ApiResource\PlayerGameBalance\PlayerGameBalance;

interface PlayerGameBalanceServiceInterface
{
    public function getGameBalance(int $playerId): PlayerGameBalance;
}
