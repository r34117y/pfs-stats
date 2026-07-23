<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class PlayerGameBalanceEndpointTest extends ApiEndpointTestCase
{
    public function testGetsPlayerGameBalanceFromColdCache(): void
    {
        $client = self::createApiClient();
        $player = self::devData()->findPfsPlayerWithTournamentGames();

        if ($player === null) {
            self::markTestSkipped('No PFS player with tournament games exists in the dev database.');
        }

        $response = self::requestJsonGet($client, '/api/players/' . $player['slug'] . '/game-balance');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('rows', $data);
        self::assertIsArray($data['rows']);
        self::assertNotSame([], $data['rows']);

        $firstRow = $data['rows'][0];
        self::assertIsArray($firstRow);
        foreach ([
            'position',
            'opponentId',
            'opponent',
            'winPercent',
            'gameBalance',
            'smallPointsBalance',
            'wins',
            'draws',
            'losses',
            'streak',
            'averagePoints',
            'averageOpponentPoints',
            'totalGames',
        ] as $field) {
            self::assertArrayHasKey($field, $firstRow);
        }

        self::assertIsInt($firstRow['position']);
        self::assertGreaterThan(0, $firstRow['position']);
        self::assertIsInt($firstRow['opponentId']);
        self::assertGreaterThan(0, $firstRow['opponentId']);
        self::assertIsString($firstRow['opponent']);
        self::assertNotSame('', $firstRow['opponent']);
        self::assertTrue(is_float($firstRow['winPercent']) || is_int($firstRow['winPercent']));
        self::assertGreaterThanOrEqual(0, $firstRow['winPercent']);
        self::assertLessThanOrEqual(100, $firstRow['winPercent']);
        foreach (['gameBalance', 'smallPointsBalance'] as $field) {
            self::assertIsInt($firstRow[$field]);
        }
        foreach (['wins', 'draws', 'losses', 'totalGames'] as $field) {
            self::assertIsInt($firstRow[$field]);
            self::assertGreaterThanOrEqual(0, $firstRow[$field]);
        }
        self::assertGreaterThan(0, $firstRow['totalGames']);
        self::assertIsString($firstRow['streak']);
        self::assertMatchesRegularExpression('/^[+-]?\d+$/', $firstRow['streak']);
        foreach (['averagePoints', 'averageOpponentPoints'] as $field) {
            self::assertTrue(is_float($firstRow[$field]) || is_int($firstRow[$field]));
            self::assertGreaterThanOrEqual(0, $firstRow[$field]);
        }
    }
}
