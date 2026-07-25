<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class StatsPointsRecordEndpointsTest extends ApiEndpointTestCase
{
    public function testGetsPointsRecordEndpointsFromColdCache(): void
    {
        foreach ([
            '/api/stats/most-small-points',
            '/api/stats/least-small-points',
            '/api/stats/highest-points-sum',
            '/api/stats/lowest-points-sum',
            '/api/stats/most-points-and-loss',
            '/api/stats/least-points-and-win',
            '/api/stats/most-opponent-points-and-win',
            '/api/stats/least-opponent-points-and-loss',
        ] as $path) {
            $row = $this->requestFirstStatsRow($path, sprintf('No points record rows exist for %s in the dev database.', $path));

            self::assertGameRecordRow($row);
        }
    }

    public function testGetsGamesOver400FromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/games-over-400', 'No games over 400 rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        foreach (['gamesOver400', 'gamesOver40024Months', 'gamesOver40012Months'] as $field) {
            self::assertNullableNonNegativeIntField($row, $field);
        }
        foreach (['gamesOver400Percent', 'gamesOver40024MonthsPercent', 'gamesOver40012MonthsPercent'] as $field) {
            self::assertNullablePercentField($row, $field);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requestFirstStatsRow(string $path, string $skipMessage): array
    {
        $client = self::createApiClient();
        $response = self::requestJsonGet($client, $path);

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('rows', $data);
        self::assertIsArray($data['rows']);

        if ($data['rows'] === []) {
            self::markTestSkipped($skipMessage);
        }

        $row = $data['rows'][0];
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertGameRecordRow(array $row): void
    {
        self::assertPlayerPositionRow($row);
        self::assertArrayHasKey('points', $row);
        self::assertIsInt($row['points']);

        foreach (['opponentId', 'tournamentId'] as $field) {
            self::assertArrayHasKey($field, $row);
            self::assertIsInt($row[$field]);
            self::assertGreaterThan(0, $row[$field]);
        }
        foreach (['opponentName', 'score', 'tournamentName'] as $field) {
            self::assertArrayHasKey($field, $row);
            self::assertIsString($row[$field]);
            self::assertNotSame('', $row[$field]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertPlayerPositionRow(array $row): void
    {
        foreach (['position', 'playerId'] as $field) {
            self::assertArrayHasKey($field, $row);
            self::assertIsInt($row[$field]);
            self::assertGreaterThan(0, $row[$field]);
        }
        self::assertArrayHasKey('playerName', $row);
        self::assertIsString($row['playerName']);
        self::assertNotSame('', $row['playerName']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertNullableNonNegativeIntField(array $row, string $field): void
    {
        if (!array_key_exists($field, $row) || $row[$field] === null) {
            return;
        }

        self::assertIsInt($row[$field]);
        self::assertGreaterThanOrEqual(0, $row[$field]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertNullablePercentField(array $row, string $field): void
    {
        if (!array_key_exists($field, $row) || $row[$field] === null) {
            return;
        }

        self::assertIsNumeric($row[$field]);
        self::assertGreaterThanOrEqual(0, (float) $row[$field]);
        self::assertLessThanOrEqual(100, (float) $row[$field]);
    }
}
