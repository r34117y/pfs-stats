<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class PlayerProfileEndpointTest extends ApiEndpointTestCase
{
    public function testGetsPlayerProfileFromColdCache(): void
    {
        $client = self::createApiClient();
        $player = self::devData()->findPfsPlayer();

        if ($player === null) {
            self::markTestSkipped('No PFS player with a slug exists in the dev database.');
        }

        $response = self::requestJsonGet($client, '/api/players/' . $player['slug']);

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        foreach ([
            'id',
            'slug',
            'nameShow',
            'totalGamesPlayed',
            'totalTournamentsPlayed',
            'totalGamesWon',
            'gamesWonLast12Months',
            'tournamentsWonLast12Months',
            'gamesWonCurrentYear',
            'tournamentsWonCurrentYear',
        ] as $field) {
            self::assertArrayHasKey($field, $data);
        }

        self::assertSame($player['id'], $data['id']);
        self::assertSame($player['slug'], $data['slug']);
        self::assertIsString($data['nameShow']);
        self::assertNotSame('', $data['nameShow']);

        foreach (['city', 'bio', 'photoUrl'] as $field) {
            if (array_key_exists($field, $data)) {
                self::assertTrue($data[$field] === null || is_string($data[$field]));
            }
        }
        if (array_key_exists('age', $data)) {
            self::assertTrue($data['age'] === null || is_int($data['age']));
        }
        if (array_key_exists('firstTournament', $data)) {
            self::assertProfileTournamentShape($data['firstTournament']);
        }
        if (array_key_exists('lastTournament', $data)) {
            self::assertProfileTournamentShape($data['lastTournament']);
        }
        if (array_key_exists('currentRank', $data)) {
            self::assertTrue($data['currentRank'] === null || is_float($data['currentRank']) || is_int($data['currentRank']));
        }
        if (array_key_exists('currentPosition', $data)) {
            self::assertTrue($data['currentPosition'] === null || is_int($data['currentPosition']));
        }

        foreach ([
            'totalGamesPlayed',
            'totalTournamentsPlayed',
            'totalGamesWon',
            'gamesWonLast12Months',
            'tournamentsWonLast12Months',
            'gamesWonCurrentYear',
            'tournamentsWonCurrentYear',
        ] as $field) {
            self::assertIsInt($data[$field]);
            self::assertGreaterThanOrEqual(0, $data[$field]);
        }
    }

    private static function assertProfileTournamentShape(mixed $tournament): void
    {
        if ($tournament === null) {
            self::assertNull($tournament);

            return;
        }

        self::assertIsArray($tournament);
        foreach (['id', 'name', 'date'] as $field) {
            self::assertArrayHasKey($field, $tournament);
        }
        self::assertIsInt($tournament['id']);
        self::assertGreaterThan(0, $tournament['id']);
        self::assertIsString($tournament['name']);
        self::assertIsInt($tournament['date']);
        self::assertGreaterThan(0, $tournament['date']);
    }
}
