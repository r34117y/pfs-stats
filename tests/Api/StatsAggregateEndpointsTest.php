<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class StatsAggregateEndpointsTest extends ApiEndpointTestCase
{
    public function testGetsAllTimeSummaryFromColdCache(): void
    {
        $data = $this->requestStatsRows('/api/stats/all-time-summary');

        self::assertNotSame([], $data['rows']);

        $row = $data['rows'][0];
        self::assertIsArray($row);
        foreach (['statisticName', 'allTimesValue', 'last12MonthsValue'] as $field) {
            self::assertArrayHasKey($field, $row);
            self::assertIsString($row[$field]);
            self::assertNotSame('', $row[$field]);
        }
    }

    public function testGetsAllTimesResultsFromColdCache(): void
    {
        $data = $this->requestStatsRows('/api/stats/all-times-results');

        if ($data['rows'] === []) {
            self::markTestSkipped('No all-time results rows exist in the dev database.');
        }

        self::assertAllTimesResultsPlayerRowShape($data['rows'][0]);
    }

    public function testGetsYearlyAllTimesResultsFromColdCache(): void
    {
        $data = $this->requestStatsRows('/api/stats/yearly-all-times-results');

        if ($data['rows'] === []) {
            self::markTestSkipped('No yearly all-time results rows exist in the dev database for the default year.');
        }

        self::assertAllTimesResultsPlayerRowShape($data['rows'][0]);
    }

    public function testGetsYearlyRankingSummaryFromColdCache(): void
    {
        $data = $this->requestStatsRows('/api/stats/yearly-ranking-summary');

        if ($data['rows'] === []) {
            self::markTestSkipped('No yearly ranking summary rows exist in the dev database for the default year.');
        }

        $row = $data['rows'][0];
        self::assertIsArray($row);
        foreach (['position', 'playerId', 'gamesCount'] as $field) {
            self::assertArrayHasKey($field, $row);
            self::assertIsInt($row[$field]);
            self::assertGreaterThan(0, $row[$field]);
        }
        self::assertArrayHasKey('playerName', $row);
        self::assertIsString($row['playerName']);
        self::assertNotSame('', $row['playerName']);
        self::assertArrayHasKey('rank', $row);
        self::assertIsNumeric($row['rank']);
    }

    public function testGetsRankingLeadersFromColdCache(): void
    {
        $data = $this->requestStatsRows('/api/stats/ranking-leaders');

        if ($data['rows'] === []) {
            self::markTestSkipped('No ranking leader rows exist in the dev database.');
        }

        $row = $data['rows'][0];
        self::assertIsArray($row);
        foreach (['position', 'playerId', 'daysOnTop', 'firstTournamentId', 'lastTournamentId'] as $field) {
            self::assertArrayHasKey($field, $row);
            self::assertIsInt($row[$field]);
            self::assertGreaterThan(0, $row[$field]);
        }
        foreach (['playerName', 'firstTournamentName', 'lastTournamentName'] as $field) {
            self::assertArrayHasKey($field, $row);
            self::assertIsString($row[$field]);
            self::assertNotSame('', $row[$field]);
        }
    }

    /**
     * @return array{rows: array<int, mixed>}
     */
    private function requestStatsRows(string $path): array
    {
        $client = self::createApiClient();
        $response = self::requestJsonGet($client, $path);

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('rows', $data);
        self::assertIsArray($data['rows']);

        return ['rows' => $data['rows']];
    }

    /**
     * @param mixed $row
     */
    private static function assertAllTimesResultsPlayerRowShape(mixed $row): void
    {
        self::assertIsArray($row);
        foreach ([
            'position',
            'playerId',
            'first',
            'second',
            'third',
            'fourth',
            'fifth',
            'sixth',
            'oneToThree',
            'oneToSix',
        ] as $field) {
            self::assertArrayHasKey($field, $row);
            self::assertIsInt($row[$field]);
            self::assertGreaterThanOrEqual(0, $row[$field]);
        }
        self::assertGreaterThan(0, $row['position']);
        self::assertGreaterThan(0, $row['playerId']);

        foreach (['playerName', 'slug'] as $field) {
            self::assertArrayHasKey($field, $row);
            self::assertIsString($row[$field]);
            self::assertNotSame('', $row[$field]);
        }
    }
}
