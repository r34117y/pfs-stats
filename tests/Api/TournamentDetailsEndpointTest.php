<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class TournamentDetailsEndpointTest extends ApiEndpointTestCase
{
    public function testGetsTournamentDetailsFromColdCache(): void
    {
        $client = self::createApiClient();
        $tournamentId = self::devData()->findTournamentId();

        if ($tournamentId === null) {
            self::markTestSkipped('No tournament with results exists in the dev database.');
        }

        $response = self::requestJsonGet($client, sprintf('/api/tournaments/%d/details', $tournamentId));

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        foreach (['id', 'name', 'date'] as $field) {
            self::assertArrayHasKey($field, $data);
        }

        self::assertSame($tournamentId, $data['id']);
        self::assertIsString($data['name']);
        self::assertNotSame('', $data['name']);
        self::assertIsString($data['date']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$|^\d{8}$/', $data['date']);

        foreach (['refereeName', 'address'] as $field) {
            if (array_key_exists($field, $data)) {
                self::assertTrue($data[$field] === null || is_string($data[$field]));
            }
        }
    }
}
