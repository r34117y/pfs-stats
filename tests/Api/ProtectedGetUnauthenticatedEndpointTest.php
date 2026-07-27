<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class ProtectedGetUnauthenticatedEndpointTest extends ApiEndpointTestCase
{
    public function testProtectedGetEndpointsRejectUnauthenticatedRequests(): void
    {
        $client = self::createApiClient();

        foreach ([
            '/api/user/profile/data',
            '/api/user/players/manage/data',
            '/api/user/tournament-results/add/data',
        ] as $path) {
            $response = self::requestJsonGet($client, $path);

            self::assertContains($response->getStatusCode(), [401, 403], (string) $response->getContent());

            $data = self::decodeJson($response);
            foreach (['@type', 'title', 'status'] as $field) {
                self::assertArrayHasKey($field, $data);
            }
            self::assertSame('Error', $data['@type']);
            self::assertSame($response->getStatusCode(), $data['status']);
            self::assertIsString($data['title']);
            self::assertNotSame('', $data['title']);
        }
    }
}
