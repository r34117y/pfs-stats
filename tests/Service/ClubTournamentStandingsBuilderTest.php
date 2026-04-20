<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\ClubTournamentImport\ParsedClubGame;
use App\ClubTournamentImport\ParsedClubPlayer;
use App\Service\ClubTournamentStandingsBuilder;
use PHPUnit\Framework\TestCase;

final class ClubTournamentStandingsBuilderTest extends TestCase
{
    public function testBuildsAndSortsStandings(): void
    {
        $standings = (new ClubTournamentStandingsBuilder())->buildStandings([
            1 => $this->player('Anna', 101, 201, [
                $this->game(result: '+', pointsFor: 430, pointsAgainst: 300),
                $this->game(result: '=', pointsFor: 360, pointsAgainst: 360),
            ]),
            2 => $this->player('Bartek', 102, 202, [
                $this->game(result: '+', pointsFor: 450, pointsAgainst: 300),
                $this->game(isBye: true),
            ]),
            3 => $this->player('Celina', 103, 203, [
                $this->game(result: '-', pointsFor: 300, pointsAgainst: 430),
                $this->game(result: '=', pointsFor: 360, pointsAgainst: 360),
            ]),
        ]);

        self::assertSame(['Bartek', 'Anna', 'Celina'], array_column($standings, 'nameShow'));

        self::assertSame(1, $standings[0]['wins']);
        self::assertSame(0, $standings[0]['losses']);
        self::assertSame(1, $standings[0]['draws']);
        self::assertSame(2, $standings[0]['games']);
        self::assertSame(1.5, $standings[0]['bigPoints']);
        self::assertSame(800, $standings[0]['pointsFor']);
        self::assertSame(300, $standings[0]['pointsAgainst']);
    }

    /**
     * @param list<ParsedClubGame> $games
     * @return array{source:ParsedClubPlayer,playerId:int,legacyPlayerId:int,nameShow:string,nameAlph:string}
     */
    private function player(string $name, int $playerId, int $legacyPlayerId, array $games): array
    {
        return [
            'source' => new ParsedClubPlayer(
                position: $playerId - 100,
                name: $name,
                initialRank: 150,
                city: 'Poznan',
                games: $games,
                achievedRank: 160.0,
            ),
            'playerId' => $playerId,
            'legacyPlayerId' => $legacyPlayerId,
            'nameShow' => $name,
            'nameAlph' => $name,
        ];
    }

    private function game(
        ?string $result = null,
        ?int $pointsFor = null,
        ?int $pointsAgainst = null,
        bool $isBye = false,
    ): ParsedClubGame {
        return new ParsedClubGame(
            round: 1,
            table: 1,
            opponentPosition: null,
            opponentName: $isBye ? 'PAUZA' : 'Opponent',
            opponentRank: null,
            result: $result,
            pointsFor: $pointsFor,
            pointsAgainst: $pointsAgainst,
            scalp: null,
            isBye: $isBye,
        );
    }
}
