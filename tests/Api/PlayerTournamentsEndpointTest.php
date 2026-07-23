<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class PlayerTournamentsEndpointTest extends ApiEndpointTestCase
{
    public function testGetsPlayerTournamentsFromColdCache(): void
    {
        $client = self::createApiClient();
        $player = self::devData()->findPfsPlayerWithTournamentResults();

        if ($player === null) {
            self::markTestSkipped('No PFS player with tournament results exists in the dev database.');
        }

        $response = self::requestJsonGet($client, '/api/players/' . $player['slug'] . '/tournaments');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('tournaments', $data);
        self::assertIsArray($data['tournaments']);
        self::assertNotSame([], $data['tournaments']);

        $firstTournament = $data['tournaments'][0];
        self::assertIsArray($firstTournament);
        foreach ([
            'id',
            'name',
            'shortName',
            'date',
            'tournamentRank',
            'numberOfPlayers',
            'finalPosition',
            'gamesWon',
            'gamesDraw',
            'gamesLost',
            'averagePoints',
            'averagePointsLost',
            'averagePointsSum',
            'achievedRank',
        ] as $field) {
            self::assertArrayHasKey($field, $firstTournament);
        }

        self::assertIsInt($firstTournament['id']);
        self::assertGreaterThan(0, $firstTournament['id']);
        self::assertIsString($firstTournament['name']);
        self::assertNotSame('', $firstTournament['name']);
        self::assertIsString($firstTournament['shortName']);
        self::assertIsString($firstTournament['date']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$|^\d{8}$/', $firstTournament['date']);
        self::assertIsFloat($firstTournament['tournamentRank']);
        self::assertIsInt($firstTournament['numberOfPlayers']);
        self::assertGreaterThanOrEqual(0, $firstTournament['numberOfPlayers']);
        foreach (['finalPosition', 'gamesWon', 'gamesDraw', 'gamesLost'] as $field) {
            self::assertIsInt($firstTournament[$field]);
            self::assertGreaterThanOrEqual(0, $firstTournament[$field]);
        }
        foreach (['averagePoints', 'averagePointsLost', 'averagePointsSum', 'achievedRank'] as $field) {
            self::assertTrue(is_float($firstTournament[$field]) || is_int($firstTournament[$field]));
        }
        if (array_key_exists('positionAsPercent', $firstTournament)) {
            self::assertTrue(
                $firstTournament['positionAsPercent'] === null
                || is_float($firstTournament['positionAsPercent'])
                || is_int($firstTournament['positionAsPercent']),
            );
        }
    }
}
