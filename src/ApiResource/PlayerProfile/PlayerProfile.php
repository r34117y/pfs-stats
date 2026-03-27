<?php

namespace App\ApiResource\PlayerProfile;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\PlayerProfileProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/players/{slug}',
            uriVariables: ['slug'],
            description: 'Get player profile.',
            provider: PlayerProfileProvider::class
        ),
    ],
)]
class PlayerProfile
{
    public function __construct(
        #[ApiProperty(identifier: false)]
        public int $id,
        #[ApiProperty(identifier: true)]
        public string $slug,
        public string $nameShow,
        public ?string $city,
        public ?string $bio,
        public ?int $age,
        public ?string $photoUrl,
        public ?PlayerProfileTournament $firstTournament,
        public ?PlayerProfileTournament $lastTournament,
        public ?float $currentRank,
        public ?int $currentPosition,
        public int $totalGamesPlayed,
        public int $totalTournamentsPlayed,
        public int $totalGamesWon,
        public int $gamesWonLast12Months,
        public int $tournamentsWonLast12Months,
        public int $gamesWonCurrentYear,
        public int $tournamentsWonCurrentYear,
    ) {
    }
}
