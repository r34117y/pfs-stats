<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class ClubsListEndpointTest extends ApiEndpointTestCase
{
    public function testGetsClubsListFromColdCache(): void
    {
        $client = self::createApiClient();

        $response = self::requestJsonGet($client, '/api/clubs');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('clubs', $data);
        self::assertIsArray($data['clubs']);

        if (!self::devData()->hasClubRows()) {
            self::assertSame([], $data['clubs']);

            return;
        }

        self::assertNotSame([], $data['clubs']);

        $firstClub = $data['clubs'][0];
        self::assertIsArray($firstClub);
        foreach (['id', 'name', 'city'] as $field) {
            self::assertArrayHasKey($field, $firstClub);
        }

        self::assertIsInt($firstClub['id']);
        self::assertGreaterThan(0, $firstClub['id']);
        self::assertIsString($firstClub['name']);
        self::assertNotSame('', $firstClub['name']);
        self::assertIsString($firstClub['city']);
        self::assertNotSame('', $firstClub['city']);
    }
}
