<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class RankingEndpointTest extends ApiEndpointTestCase
{
    public function testGetsCurrentRankingFromColdCache(): void
    {
        $client = self::createApiClient();

        $response = self::requestJsonGet($client, '/api/ranking');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('rows', $data);
        self::assertIsArray($data['rows']);
        self::assertArrayHasKey('lastTournamentName', $data);
        self::assertTrue($data['lastTournamentName'] === null || is_string($data['lastTournamentName']));
        self::assertArrayHasKey('lastTournamentId', $data);
        self::assertTrue($data['lastTournamentId'] === null || is_int($data['lastTournamentId']));

        if (!self::devData()->hasRankingRows()) {
            self::assertSame([], $data['rows']);

            return;
        }

        self::assertNotSame([], $data['rows']);

        $firstRow = $data['rows'][0];
        self::assertIsArray($firstRow);
        foreach ([
            'position',
            'nameShow',
            'nameAlph',
            'playerId',
            'rank',
            'numberOfGames',
            'rankDelta',
            'positionDelta',
            'slug',
        ] as $field) {
            self::assertArrayHasKey($field, $firstRow);
        }
        self::assertIsInt($firstRow['position']);
        self::assertNotSame('', $firstRow['nameShow']);
        self::assertIsString($firstRow['nameShow']);
        self::assertIsString($firstRow['nameAlph']);
        self::assertIsInt($firstRow['playerId']);
        if (array_key_exists('photo', $firstRow)) {
            self::assertTrue($firstRow['photo'] === null || is_string($firstRow['photo']));
        }
        self::assertIsString($firstRow['rank']);
        self::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $firstRow['rank']);
        self::assertIsInt($firstRow['numberOfGames']);
        self::assertTrue($firstRow['rankDelta'] === null || is_string($firstRow['rankDelta']));
        if (is_string($firstRow['rankDelta'])) {
            self::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $firstRow['rankDelta']);
        }
        self::assertTrue(
            $firstRow['positionDelta'] === null
            || is_int($firstRow['positionDelta'])
            || $firstRow['positionDelta'] === '+',
        );
        self::assertNotSame('', $firstRow['slug']);
        self::assertIsString($firstRow['slug']);
    }
}
