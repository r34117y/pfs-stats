<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class ApiGetNotFoundEndpointTest extends ApiEndpointTestCase
{
    public function testRepresentativeRouteVariableEndpointsReturnNotFoundForInvalidIdentifiers(): void
    {
        $client = self::createApiClient();

        foreach ([
            '/api/players/codex-missing-player',
            '/api/tournaments/999999999/details',
            '/api/games/999999999-1-1',
            '/api/tournaments/999999999/players/codex-missing-player/summary',
        ] as $path) {
            $response = self::requestJsonGet($client, $path);

            self::assertResponseStatus($response, 404);
            self::assertErrorResponseShape($response->getStatusCode(), self::decodeJson($response));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function assertErrorResponseShape(int $statusCode, array $data): void
    {
        foreach (['@type', 'title', 'status'] as $field) {
            self::assertArrayHasKey($field, $data);
        }

        self::assertSame('Error', $data['@type']);
        self::assertSame($statusCode, $data['status']);
        self::assertIsString($data['title']);
        self::assertNotSame('', $data['title']);

        if (array_key_exists('detail', $data)) {
            self::assertIsString($data['detail']);
            self::assertNotSame('', $data['detail']);
        }
    }
}
