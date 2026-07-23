<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class PlayersListEndpointTest extends ApiEndpointTestCase
{
    public function testGetsPlayersListFromColdCache(): void
    {
        $client = self::createApiClient();

        $response = self::requestJsonGet($client, '/api/players');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('players', $data);
        self::assertIsArray($data['players']);

        if (!self::devData()->hasDefaultOrganizationPlayerRows()) {
            self::assertSame([], $data['players']);

            return;
        }

        self::assertNotSame([], $data['players']);

        $firstPlayer = $data['players'][0];
        self::assertIsArray($firstPlayer);
        foreach (['id', 'nameShow', 'nameAlph', 'slug'] as $field) {
            self::assertArrayHasKey($field, $firstPlayer);
        }

        self::assertIsInt($firstPlayer['id']);
        self::assertGreaterThan(0, $firstPlayer['id']);
        self::assertIsString($firstPlayer['nameShow']);
        self::assertNotSame('', $firstPlayer['nameShow']);
        self::assertIsString($firstPlayer['nameAlph']);
        self::assertNotSame('', $firstPlayer['nameAlph']);
        self::assertTrue($firstPlayer['slug'] === null || is_string($firstPlayer['slug']));
        if (is_string($firstPlayer['slug'])) {
            self::assertNotSame('', $firstPlayer['slug']);
        }
        if (array_key_exists('photo', $firstPlayer)) {
            self::assertTrue($firstPlayer['photo'] === null || is_string($firstPlayer['photo']));
        }
    }
}
