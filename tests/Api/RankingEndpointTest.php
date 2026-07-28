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
        self::assertRankingResponseShape($data, self::devData()->hasRankingRows());
        if (self::devData()->hasRankingRows()) {
            self::assertIsInt($data['previousTournamentId']);
            self::assertNull($data['nextTournamentId'] ?? null);
        }
    }

    public function testGetsRankingAfterRequestedTournament(): void
    {
        $tournamentId = self::devData()->findDefaultOrganizationRankingTournamentId();
        if ($tournamentId === null) {
            self::markTestSkipped('No default-organization ranking snapshot exists in the dev database.');
        }

        $client = self::createApiClient();

        $response = self::requestJsonGet($client, '/api/ranking', [
            'tournamentId' => (string) $tournamentId,
        ]);

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertRankingResponseShape($data, true);
        self::assertSame($tournamentId, $data['lastTournamentId']);
        self::assertNull($data['previousTournamentId'] ?? null);
        self::assertIsInt($data['nextTournamentId']);
    }
}
