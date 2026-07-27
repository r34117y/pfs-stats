<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Component\HttpFoundation\Request;

final class ApiColdCacheRegressionTest extends ApiEndpointTestCase
{
    public function testCacheableEndpointRemainsValidAfterPrimeAndClear(): void
    {
        $client = self::createApiClient();
        self::clearApiCache();

        $primedResponse = $client->handle(Request::create('/api/organizations', 'GET', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
        ]));

        self::assertResponseStatus($primedResponse, 200);
        self::assertPublicApiCacheHeaders($primedResponse);
        $primedData = self::decodeJson($primedResponse);
        self::assertOrganizationsResponseShape($primedData);

        self::clearApiCache();

        $coldResponse = self::requestJsonGet($client, '/api/organizations');

        self::assertResponseStatus($coldResponse, 200);
        self::assertPublicApiCacheHeaders($coldResponse);
        $coldData = self::decodeJson($coldResponse);
        self::assertOrganizationsResponseShape($coldData);
        self::assertSame(
            self::organizationBusinessRows($primedData),
            self::organizationBusinessRows($coldData),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function assertOrganizationsResponseShape(array $data): void
    {
        self::assertArrayHasKey('organizations', $data);
        self::assertIsArray($data['organizations']);

        if (self::devData()->findOrganizationId() === null) {
            self::assertSame([], $data['organizations']);

            return;
        }

        self::assertNotSame([], $data['organizations']);

        $organization = $data['organizations'][0];
        self::assertIsArray($organization);
        foreach (['id', 'code', 'name'] as $field) {
            self::assertArrayHasKey($field, $organization);
        }
        self::assertIsInt($organization['id']);
        self::assertGreaterThan(0, $organization['id']);
        self::assertIsString($organization['code']);
        self::assertNotSame('', $organization['code']);
        self::assertIsString($organization['name']);
        self::assertNotSame('', $organization['name']);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{id: int, code: string, name: string}>
     */
    private static function organizationBusinessRows(array $data): array
    {
        self::assertArrayHasKey('organizations', $data);
        self::assertIsArray($data['organizations']);

        return array_map(
            static function (mixed $organization): array {
                self::assertIsArray($organization);

                return [
                    'id' => $organization['id'],
                    'code' => $organization['code'],
                    'name' => $organization['name'],
                ];
            },
            $data['organizations'],
        );
    }
}
