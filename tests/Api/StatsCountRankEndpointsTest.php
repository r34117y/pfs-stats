<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class StatsCountRankEndpointsTest extends ApiEndpointTestCase
{
    public function testGetsGamesCountFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/games', 'No games count rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        foreach (['gamesCount', 'last24MonthsGamesCount', 'last12MonthsGamesCount'] as $field) {
            self::assertNonNegativeIntField($row, $field);
        }
        self::assertGreaterThan(0, $row['gamesCount']);
    }

    public function testGetsGamesWonFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/games-won', 'No games won rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        foreach (['gamesWon', 'gamesWon24Months', 'gamesWon12Months'] as $field) {
            self::assertNonNegativeIntField($row, $field);
        }
        foreach (['gamesWonPercent', 'gamesWon24MonthsPercent', 'gamesWon12MonthsPercent'] as $field) {
            self::assertPercentField($row, $field);
        }
    }

    public function testGetsTournamentsCountFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/tournaments', 'No tournament count rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        foreach (['tournamentsCount', 'last24MonthsTournamentsCount', 'last12MonthsTournamentsCount'] as $field) {
            self::assertNonNegativeIntField($row, $field);
        }
        self::assertGreaterThan(0, $row['tournamentsCount']);
    }

    public function testGetsRankAllGamesFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/rank-all-games', 'No all-games ranking rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        self::assertPositiveNumericField($row, 'rankAllGames');
        self::assertNullableNumericField($row, 'rank24Months');
        self::assertNullableNumericField($row, 'rank12Months');
    }

    public function testGetsHighestRankFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/highest-rank', 'No highest rank rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        self::assertPositiveNumericField($row, 'highestRank');
        self::assertNullableNumericField($row, 'highestRank24Months');
        self::assertNullableNumericField($row, 'highestRank12Months');
    }

    public function testGetsHighestRankPositionFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/highest-rank-position', 'No highest rank position rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        self::assertNonNegativeIntField($row, 'highestRankPosition');
        self::assertNullableIntField($row, 'highestRankPosition24Months');
        self::assertNullableIntField($row, 'highestRankPosition12Months');
        self::assertGreaterThan(0, $row['highestRankPosition']);
    }

    public function testGetsHighestTournamentRankRecordFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/highest-tournament-rank-record', 'No highest tournament rank record rows exist in the dev database.');

        self::assertTournamentRankRecordRow($row);
    }

    public function testGetsLowestTournamentRankRecordFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/lowest-tournament-rank-record', 'No lowest tournament rank record rows exist in the dev database.');

        self::assertTournamentRankRecordRow($row);
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
    private static function assertTournamentRankRecordRow(array $row): void
    {
        self::assertPlayerPositionRow($row);
        self::assertPositiveNumericField($row, 'ranking');
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
    private static function assertNonNegativeIntField(array $row, string $field): void
    {
        self::assertArrayHasKey($field, $row);
        self::assertIsInt($row[$field]);
        self::assertGreaterThanOrEqual(0, $row[$field]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertNullableIntField(array $row, string $field): void
    {
        self::assertArrayHasKey($field, $row);
        if ($row[$field] === null) {
            return;
        }

        self::assertIsInt($row[$field]);
        self::assertGreaterThan(0, $row[$field]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertPositiveNumericField(array $row, string $field): void
    {
        self::assertArrayHasKey($field, $row);
        self::assertIsNumeric($row[$field]);
        self::assertGreaterThan(0, (float) $row[$field]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertNullableNumericField(array $row, string $field): void
    {
        self::assertArrayHasKey($field, $row);
        if ($row[$field] === null) {
            return;
        }

        self::assertIsNumeric($row[$field]);
        self::assertGreaterThan(0, (float) $row[$field]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertPercentField(array $row, string $field): void
    {
        self::assertArrayHasKey($field, $row);
        self::assertIsNumeric($row[$field]);
        self::assertGreaterThanOrEqual(0, (float) $row[$field]);
        self::assertLessThanOrEqual(100, (float) $row[$field]);
    }
}
