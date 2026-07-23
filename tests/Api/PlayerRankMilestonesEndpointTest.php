<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class PlayerRankMilestonesEndpointTest extends ApiEndpointTestCase
{
    public function testGetsPlayerRankMilestonesFromColdCache(): void
    {
        $client = self::createApiClient();
        $player = self::devData()->findPfsPlayerWithRankHistory();

        if ($player === null) {
            self::markTestSkipped('No PFS player with rank history exists in the dev database.');
        }

        $response = self::requestJsonGet($client, '/api/players/' . $player['slug'] . '/rank-history/milestones');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('milestones', $data);
        self::assertIsArray($data['milestones']);

        if ($data['milestones'] === []) {
            self::assertSame([], $data['milestones']);

            return;
        }

        $previousMilestone = 0;
        foreach ($data['milestones'] as $milestone) {
            self::assertIsArray($milestone);
            foreach (['milestone', 'date', 'tournamentId', 'tournamentName', 'rank'] as $field) {
                self::assertArrayHasKey($field, $milestone);
            }

            self::assertIsInt($milestone['milestone']);
            self::assertGreaterThan($previousMilestone, $milestone['milestone']);
            self::assertSame(0, $milestone['milestone'] % 10);
            self::assertGreaterThanOrEqual(100, $milestone['milestone']);
            self::assertIsString($milestone['date']);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$|^\d{8}$/', $milestone['date']);
            self::assertIsInt($milestone['tournamentId']);
            self::assertGreaterThan(0, $milestone['tournamentId']);
            self::assertIsString($milestone['tournamentName']);
            self::assertTrue(is_float($milestone['rank']) || is_int($milestone['rank']));
            self::assertGreaterThanOrEqual($milestone['milestone'], $milestone['rank']);

            $previousMilestone = $milestone['milestone'];
        }
    }
}
