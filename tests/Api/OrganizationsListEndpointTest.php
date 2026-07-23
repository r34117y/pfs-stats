<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class OrganizationsListEndpointTest extends ApiEndpointTestCase
{
    public function testGetsOrganizationsListFromColdCache(): void
    {
        $client = self::createApiClient();

        $response = self::requestJsonGet($client, '/api/organizations');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('organizations', $data);
        self::assertIsArray($data['organizations']);

        if (self::devData()->findOrganizationId() === null) {
            self::assertSame([], $data['organizations']);

            return;
        }

        self::assertNotSame([], $data['organizations']);

        $firstOrganization = $data['organizations'][0];
        self::assertIsArray($firstOrganization);
        foreach (['id', 'code', 'name'] as $field) {
            self::assertArrayHasKey($field, $firstOrganization);
        }

        self::assertIsInt($firstOrganization['id']);
        self::assertGreaterThan(0, $firstOrganization['id']);
        self::assertIsString($firstOrganization['code']);
        self::assertNotSame('', $firstOrganization['code']);
        self::assertIsString($firstOrganization['name']);
        self::assertNotSame('', $firstOrganization['name']);
    }
}
