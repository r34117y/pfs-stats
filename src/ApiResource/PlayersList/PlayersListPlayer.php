<?php

namespace App\ApiResource\PlayersList;

class PlayersListPlayer
{
    public function __construct(
        public int $id,
        public string $nameShow,
        public string $nameAlph,
    ) {
    }
}
