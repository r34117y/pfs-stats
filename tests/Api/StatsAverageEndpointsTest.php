<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class StatsAverageEndpointsTest extends ApiEndpointTestCase
{
    public function testGetsAveragePointsPerGameFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/avg-points-per-game', 'No average points per game rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        self::assertNumericField($row, 'averagePoints');
        self::assertNumericField($row, 'last24MonthsAveragePoints');
        self::assertNumericField($row, 'last12MonthsAveragePoints');
    }

    public function testGetsAverageOpponentsPointsFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/avg-opponents-points', 'No average opponents points rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        self::assertNumericField($row, 'averageOpponentPoints');
        self::assertNullableNumericField($row, 'last24MonthsAverageOpponentPoints');
        self::assertNullableNumericField($row, 'last12MonthsAverageOpponentPoints');
    }

    public function testGetsAveragePointsSumFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/avg-points-sum', 'No average points sum rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        self::assertNumericField($row, 'averagePointsSum');
        self::assertNullableNumericField($row, 'last24MonthsAveragePointsSum');
        self::assertNullableNumericField($row, 'last12MonthsAveragePointsSum');
    }

    public function testGetsAveragePointsDifferenceFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/avg-points-difference', 'No average points difference rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        self::assertNumericField($row, 'averagePointsDifference');
        self::assertNullableNumericField($row, 'last24MonthsAveragePointsDifference');
        self::assertNullableNumericField($row, 'last12MonthsAveragePointsDifference');
    }

    public function testGetsHighestAveragePointsSumFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/highest-avg-points-sum', 'No highest average points sum rows exist in the dev database.');

        self::assertTournamentAverageRecordRow($row);
    }

    public function testGetsLowestAveragePointsSumFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/lowest-avg-points-sum', 'No lowest average points sum rows exist in the dev database.');

        self::assertTournamentAverageRecordRow($row);
    }

    public function testGetsHighestAveragePointsDiffFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/highest-avg-points-diff', 'No highest average points diff rows exist in the dev database.');

        self::assertTournamentAverageRecordRow($row);
    }

    public function testGetsLowestAveragePointsDiffFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/lowest-avg-points-diff', 'No lowest average points diff rows exist in the dev database.');

        self::assertTournamentAverageRecordRow($row);
    }

    public function testGetsHighestAverageSmallPointsFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/highest-avg-small-points', 'No highest average small points rows exist in the dev database.');

        self::assertTournamentAverageRecordRow($row);
    }

    public function testGetsLowestAverageSmallPointsFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/lowest-avg-small-points', 'No lowest average small points rows exist in the dev database.');

        self::assertTournamentAverageRecordRow($row);
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
    private static function assertTournamentAverageRecordRow(array $row): void
    {
        self::assertPlayerPositionRow($row);
        self::assertNumericField($row, 'points');

        foreach (['result', 'tournamentName'] as $field) {
            self::assertArrayHasKey($field, $row);
            self::assertIsString($row[$field]);
            self::assertNotSame('', $row[$field]);
        }

        self::assertArrayHasKey('tournamentId', $row);
        self::assertIsInt($row['tournamentId']);
        self::assertGreaterThan(0, $row['tournamentId']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertNumericField(array $row, string $field): void
    {
        self::assertArrayHasKey($field, $row);
        self::assertIsNumeric($row[$field]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertNullableNumericField(array $row, string $field): void
    {
        if (!array_key_exists($field, $row)) {
            return;
        }

        if ($row[$field] === null) {
            return;
        }

        self::assertIsNumeric($row[$field]);
    }
}
