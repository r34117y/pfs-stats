<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class PlayerRankHistoryEndpointTest extends ApiEndpointTestCase
{
    public function testGetsPlayerRankHistoryFromColdCache(): void
    {
        $client = self::createApiClient();
        $player = self::devData()->findPfsPlayerWithRankHistory();

        if ($player === null) {
            self::markTestSkipped('No PFS player with rank history exists in the dev database.');
        }

        $response = self::requestJsonGet($client, '/api/players/' . $player['slug'] . '/rank-history');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('history', $data);
        self::assertIsArray($data['history']);
        self::assertNotSame([], $data['history']);

        $previousDate = null;
        foreach ($data['history'] as $point) {
            self::assertIsArray($point);
            foreach (['tournamentId', 'tournamentName', 'date', 'rank'] as $field) {
                self::assertArrayHasKey($field, $point);
            }

            self::assertIsInt($point['tournamentId']);
            self::assertGreaterThan(0, $point['tournamentId']);
            self::assertIsString($point['tournamentName']);
            self::assertIsString($point['date']);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$|^\d{8}$/', $point['date']);
            self::assertTrue(is_float($point['rank']) || is_int($point['rank']));

            if ($previousDate !== null) {
                self::assertGreaterThanOrEqual($previousDate, $point['date']);
            }
            $previousDate = $point['date'];
        }
    }
}
