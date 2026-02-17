<?php

namespace App\ApiResource\PlayerTournamentSummary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\PlayerTournamentSummaryProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/tournaments/{tournamentId}/players/{playerId}/summary',
            description: 'Get player summary for selected tournament.',
            provider: PlayerTournamentSummaryProvider::class
        ),
    ],
)]
class PlayerTournamentSummary
{
    /**
     * @param PlayerTournamentSummaryGame[] $games
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $tournamentId,
        #[ApiProperty(identifier: true)]
        public int $playerId,
        public string $playerName,
        public string $tournamentName,
        public PlayerTournamentSummaryStats $stats,
        public array $games,
    ) {
    }
}
