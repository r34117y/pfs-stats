<?php

namespace App\ApiResource\Stats;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\MostOpponentPointsAndWinProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/stats/most-opponent-points-and-win',
            description: 'Get won games where opponent scored the most points (top 1000).',
            provider: MostOpponentPointsAndWinProvider::class
        ),
    ],
)]
class MostOpponentPointsAndWin
{
    /**
     * @param MostOpponentPointsAndWinRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
