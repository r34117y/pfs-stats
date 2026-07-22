<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\PfsTournamentImport\CalendarTournament;
use App\PfsTournamentImport\ParsedTournamentPlayerGame;
use App\PfsTournamentImport\ParsedTournamentPlayerResults;
use App\PfsTournamentImport\ParsedTournamentResults;
use App\PfsTournamentImport\ParsedTournamentRoundGame;
use App\PfsTournamentImport\ParsedTournamentStandingRow;
use App\Service\PfsTournamentPayloadFactory;
use PHPUnit\Framework\TestCase;

final class PfsTournamentPayloadFactoryTest extends TestCase
{
    public function testCreatesTournamentRoundPayloadAcceptedByImportServiceShape(): void
    {
        $factory = new PfsTournamentPayloadFactory();
        $calendarTournament = new CalendarTournament(
            urlId: 1421,
            name: 'VIII Babskie granie (turniej bez jaj)',
            location: 'Katowice',
            startDate: new \DateTimeImmutable('2026-03-14'),
            endDate: new \DateTimeImmutable('2026-03-14'),
        );
        $results = new ParsedTournamentResults(
            tournamentName: 'VIII Babskie granie (turniej bez jaj)',
            details: [],
            players: [
                new ParsedTournamentPlayerResults(
                    playerName: 'Justyna Górka',
                    tournamentRank: 168.72,
                    city: 'Kraków',
                    games: [
                        new ParsedTournamentPlayerGame(1, 1, 'Marta Szcześniak', 139.40, '+', 420, 390, 189),
                    ],
                    totalScalp: 998,
                    roundsPlayed: 7,
                    rankAchieved: 142.57,
                ),
                new ParsedTournamentPlayerResults(
                    playerName: 'Marta Szcześniak',
                    tournamentRank: 139.40,
                    city: 'Krzyków',
                    games: [
                        new ParsedTournamentPlayerGame(1, 1, 'Justyna Górka', 168.72, '-', 390, 420, 89),
                    ],
                    totalScalp: 1141,
                    roundsPlayed: 7,
                    rankAchieved: 163.00,
                ),
                new ParsedTournamentPlayerResults(
                    playerName: 'Alicja Bierkat',
                    tournamentRank: 124.18,
                    city: 'Ruda Śląska',
                    games: [
                        new ParsedTournamentPlayerGame(1, 2, 'PAUZA', null, null, null, null, null, true),
                    ],
                    totalScalp: 1242,
                    roundsPlayed: 7,
                    rankAchieved: 177.43,
                ),
            ],
            standings: [
                new ParsedTournamentStandingRow(1, 'Alicja Bierkat', 'Ruda Śląska', 124.18, 7.0, 2662, 1242, 488),
                new ParsedTournamentStandingRow(2, 'Marta Szcześniak', 'Krzyków', 139.40, 6.0, 2852, 1141, 352),
                new ParsedTournamentStandingRow(3, 'Justyna Górka', 'Kraków', 168.72, 5.0, 2899, 998, 666),
            ],
            roundGames: [
                new ParsedTournamentRoundGame(1, 1, 'Justyna Górka', 'Marta Szcześniak', 420, 390),
                new ParsedTournamentRoundGame(1, 2, 'Alicja Bierkat', null, null, null, true),
            ],
        );

        $payload = $factory->create($calendarTournament, $results);

        self::assertSame([
            'org' => 'pfs',
            'dateStart' => '2026-03-14',
            'name' => 'VIII Babskie granie (turniej bez jaj)',
            'city' => 'Katowice',
        ], $payload->tournament);

        self::assertSame([
            'startingPosition' => 3,
            'place' => 1,
            'ppGroup' => '',
            'firstName' => 'Alicja',
            'lastName' => 'Bierkat',
            'city' => 'Ruda Śląska',
            'tournamentRank' => 124.18,
            'big' => 7.0,
            'small' => 2662,
            'scalps' => 1242,
            'difference' => 488,
        ], $payload->players[0]);
        self::assertSame([
            'round' => 1,
            'table' => 1,
            'host' => 1,
            'guest' => 2,
            'score1' => 420,
            'score2' => 390,
        ], $payload->results[0]);
        self::assertSame([
            'round' => 1,
            'table' => 2,
            'host' => 3,
            'guest' => 0,
            'score1' => 300,
            'score2' => 0,
        ], $payload->results[1]);
        self::assertSame([
            'lp' => 1,
            'main' => true,
            'player' => 'Justyna Górka',
            'city' => 'Kraków',
            'rank' => 168.72,
            'scalp' => 998,
            'games' => 7,
        ], $payload->ranking[0]);
        self::assertSame('2026-03-14', $payload->rankDay);
    }

    public function testFailsWhenRoundGamePlayerCannotBeResolved(): void
    {
        $factory = new PfsTournamentPayloadFactory();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not resolve game player "Missing Player".');

        $factory->create(
            new CalendarTournament(
                urlId: 1,
                name: 'Test',
                location: 'City',
                startDate: new \DateTimeImmutable('2026-01-01'),
                endDate: new \DateTimeImmutable('2026-01-01'),
            ),
            new ParsedTournamentResults(
                tournamentName: 'Test',
                details: [],
                players: [
                    new ParsedTournamentPlayerResults('Known Player', 100.0, 'City', [], 0, 0, 100.0),
                ],
                standings: [
                    new ParsedTournamentStandingRow(1, 'Known Player', 'City', 100.0, 1.0, 300, 100, 0),
                ],
                roundGames: [
                    new ParsedTournamentRoundGame(1, 1, 'Known Player', 'Missing Player', 300, 250),
                ],
            ),
        );
    }
}
