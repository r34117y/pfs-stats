<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class ApiCacheHeadersTest extends ApiEndpointTestCase
{
    public function testPublicGetEndpointUsesSharedApiCacheHeaders(): void
    {
        $client = self::createApiClient();

        $response = self::requestJsonGet($client, '/api/organizations');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);
    }

    public function testProfileGetEndpointUsesPrivateNoStoreCacheHeaders(): void
    {
        $client = self::createApiClient();
        $adminUser = self::devData()->findOrganizationAdminUser();

        if ($adminUser === null) {
            self::markTestSkipped('No organization-admin user exists in the dev database.');
        }

        $response = self::requestJsonGetAsUser(
            $client,
            '/api/user/profile/data',
            self::findUser($adminUser['id']),
        );

        self::assertResponseStatus($response, 200);
        self::assertPrivateNoStoreApiCacheHeaders($response);
    }
}
