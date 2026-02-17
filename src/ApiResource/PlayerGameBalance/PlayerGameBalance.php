<?php

namespace App\ApiResource\PlayerGameBalance;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\PlayerGameBalanceProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/players/{id}/game-balance',
            description: 'Get aggregated game balance by opponent.',
            provider: PlayerGameBalanceProvider::class
        ),
    ],
)]
class PlayerGameBalance
{
    /**
     * @param PlayerGameBalanceRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
