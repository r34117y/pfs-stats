<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class TournamentResultsEndpointTest extends ApiEndpointTestCase
{
    public function testGetsTournamentResultsFromColdCache(): void
    {
        $client = self::createApiClient();
        $tournamentId = self::devData()->findTournamentId();

        if ($tournamentId === null) {
            self::markTestSkipped('No tournament with results exists in the dev database.');
        }

        $response = self::requestJsonGet($client, sprintf('/api/tournaments/%d/results', $tournamentId));

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
            'playerId',
            'playerName',
            'gamesCount',
            'rankBefore',
            'wins',
            'totalPointsScored',
            'diff',
            'sumPoints',
            'scalp',
            'rankAchieved',
            'avgOpponentRank',
        ] as $field) {
            self::assertArrayHasKey($field, $firstRow);
        }

        foreach (['position', 'playerId', 'gamesCount', 'wins', 'totalPointsScored', 'diff', 'sumPoints'] as $field) {
            self::assertIsInt($firstRow[$field]);
        }
        self::assertGreaterThanOrEqual(0, $firstRow['position']);
        self::assertGreaterThan(0, $firstRow['playerId']);
        self::assertIsString($firstRow['playerName']);
        self::assertNotSame('', $firstRow['playerName']);

        foreach (['rankBefore', 'scalp', 'rankAchieved', 'avgOpponentRank'] as $field) {
            self::assertTrue(is_float($firstRow[$field]) || is_int($firstRow[$field]));
        }
    }
}
