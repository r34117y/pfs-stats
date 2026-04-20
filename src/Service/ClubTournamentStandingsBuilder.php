<?php

declare(strict_types=1);

namespace App\Service;

use App\ClubTournamentImport\ParsedClubPlayer;

final readonly class ClubTournamentStandingsBuilder
{
    /**
     * @param array<int, array{source:ParsedClubPlayer,playerId:int,legacyPlayerId:int,nameShow:string,nameAlph:string}> $playersByPosition
     * @return list<array<string, int|float|string>>
     */
    public function buildStandings(array $playersByPosition): array
    {
        $standings = [];
        foreach ($playersByPosition as $position => $player) {
            $source = $player['source'];
            $wins = 0;
            $losses = 0;
            $draws = 0;
            $games = 0;
            $bigPoints = 0.0;
            $pointsFor = 0;
            $pointsAgainst = 0;

            foreach ($source->games as $game) {
                $games++;
                if ($game->isBye) {
                    $draws++;
                    $bigPoints += 0.5;
                    $pointsFor += 350;
                    continue;
                }

                $pointsFor += $game->pointsFor ?? 0;
                $pointsAgainst += $game->pointsAgainst ?? 0;

                if ($game->result === '+') {
                    $wins++;
                    $bigPoints += 1.0;
                } elseif ($game->result === '-') {
                    $losses++;
                } elseif ($game->result === '=') {
                    $draws++;
                    $bigPoints += 0.5;
                }
            }

            $standings[] = [
                'position' => $position,
                'playerId' => $player['playerId'],
                'legacyPlayerId' => $player['legacyPlayerId'],
                'nameShow' => $player['nameShow'],
                'initialRank' => $source->initialRank,
                'achievedRank' => $source->achievedRank,
                'wins' => $wins,
                'losses' => $losses,
                'draws' => $draws,
                'games' => $games,
                'bigPoints' => $bigPoints,
                'pointsFor' => $pointsFor,
                'pointsAgainst' => $pointsAgainst,
            ];
        }

        usort(
            $standings,
            static fn (array $left, array $right): int => [$right['bigPoints'], $right['pointsFor'], -$right['position']]
                <=> [$left['bigPoints'], $left['pointsFor'], -$left['position']],
        );

        return $standings;
    }
}
