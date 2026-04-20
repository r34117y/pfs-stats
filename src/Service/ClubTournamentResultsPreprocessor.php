<?php

declare(strict_types=1);

namespace App\Service;

use App\ClubTournamentImport\ClubTournamentResultsPreview;
use App\ClubTournamentImport\ParsedClubPlayer;

final readonly class ClubTournamentResultsPreprocessor
{
    public function __construct(
        private ClubTournamentResultsFileDecoder $fileDecoder,
        private ClubTournamentResultsParser $parser,
        private ClubTournamentStandingsBuilder $standingsBuilder,
    ) {
    }

    public function preprocess(string $raw): ClubTournamentResultsPreview
    {
        $results = $this->parser->parse($this->fileDecoder->decode($raw));

        return new ClubTournamentResultsPreview(
            results: $results,
            standings: $this->standingsBuilder->buildStandings($this->playersByPosition($results->players)),
        );
    }

    /**
     * @param list<ParsedClubPlayer> $players
     * @return array<int, array{source:ParsedClubPlayer,playerId:int,legacyPlayerId:int,nameShow:string,nameAlph:string}>
     */
    private function playersByPosition(array $players): array
    {
        $playersByPosition = [];
        foreach ($players as $player) {
            $playersByPosition[$player->position] = [
                'source' => $player,
                'playerId' => $player->position,
                'legacyPlayerId' => $player->position,
                'nameShow' => $player->name,
                'nameAlph' => $player->name,
            ];
        }

        return $playersByPosition;
    }
}
