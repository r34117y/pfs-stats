<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class StatsStreakEndpointsTest extends ApiEndpointTestCase
{
    public function testGetsPlayerStreakEndpointsFromColdCache(): void
    {
        foreach ([
            '/api/stats/longest-win-streaks' => 'winsStreak',
            '/api/stats/longest-loss-streaks' => 'lossesStreak',
            '/api/stats/longest-streak-min-350' => 'gamesStreak',
            '/api/stats/longest-streak-min-400' => 'gamesStreak',
            '/api/stats/longest-streak-sum-min-750' => 'gamesStreak',
            '/api/stats/longest-streak-sum-min-800' => 'gamesStreak',
        ] as $path => $streakField) {
            $row = $this->requestFirstStatsRow($path, sprintf('No player streak rows exist for %s in the dev database.', $path));

            self::assertPlayerStreakRow($row, $streakField);
        }
    }

    public function testGetsLongestWinStreakVsPlayerFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/longest-win-streak-vs-player', 'No win streak vs player rows exist in the dev database.');

        foreach (['position', 'winnerId', 'opponentId', 'winsStreak', 'firstTournamentId', 'lastTournamentId'] as $field) {
            self::assertPositiveIntField($row, $field);
        }
        foreach (['winnerName', 'opponentName', 'firstTournamentName', 'lastTournamentName'] as $field) {
            self::assertNonEmptyStringField($row, $field);
        }
    }

    public function testGetsDifferentOpponentsFromColdCache(): void
    {
        $row = $this->requestFirstStatsRow('/api/stats/different-opponents', 'No different opponents rows exist in the dev database.');

        self::assertPlayerPositionRow($row);
        foreach (['opponentsCount', 'last24MonthsOpponentsCount', 'last12MonthsOpponentsCount'] as $field) {
            self::assertNonNegativeIntField($row, $field);
        }
        self::assertGreaterThan(0, $row['opponentsCount']);
    }

    public function testGetsHighestVictoryAndDrawFromColdCache(): void
    {
        foreach ([
            '/api/stats/highest-victory',
            '/api/stats/highest-draw',
        ] as $path) {
            $row = $this->requestFirstStatsRow($path, sprintf('No game record rows exist for %s in the dev database.', $path));

            self::assertGameRecordRow($row);
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
    private static function assertPlayerStreakRow(array $row, string $streakField): void
    {
        self::assertPlayerPositionRow($row);
        self::assertPositiveIntField($row, $streakField);
        self::assertArrayHasKey('currentStreak', $row);
        self::assertIsInt($row['currentStreak']);

        self::assertArrayHasKey('tournaments', $row);
        self::assertIsArray($row['tournaments']);
        self::assertNotSame([], $row['tournaments']);

        $tournament = $row['tournaments'][0];
        self::assertIsArray($tournament);
        self::assertPositiveIntField($tournament, 'id');
        self::assertNonEmptyStringField($tournament, 'name');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertGameRecordRow(array $row): void
    {
        self::assertPlayerPositionRow($row);
        foreach (['points', 'opponentId', 'tournamentId'] as $field) {
            self::assertPositiveIntField($row, $field);
        }
        foreach (['opponentName', 'score', 'tournamentName'] as $field) {
            self::assertNonEmptyStringField($row, $field);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertPlayerPositionRow(array $row): void
    {
        self::assertPositiveIntField($row, 'position');
        self::assertPositiveIntField($row, 'playerId');
        self::assertNonEmptyStringField($row, 'playerName');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertPositiveIntField(array $row, string $field): void
    {
        self::assertArrayHasKey($field, $row);
        self::assertIsInt($row[$field]);
        self::assertGreaterThan(0, $row[$field]);
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
    private static function assertNonEmptyStringField(array $row, string $field): void
    {
        self::assertArrayHasKey($field, $row);
        self::assertIsString($row[$field]);
        self::assertNotSame('', $row[$field]);
    }
}
