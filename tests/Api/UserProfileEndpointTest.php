<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class UserProfileEndpointTest extends ApiEndpointTestCase
{
    public function testGetsAuthenticatedUserProfileData(): void
    {
        $client = self::createApiClient();
        $adminUser = self::devData()->findOrganizationAdminUser();

        if ($adminUser === null) {
            self::markTestSkipped('No organization-admin user exists in the dev database.');
        }

        $user = self::findUser($adminUser['id']);
        $response = self::requestJsonGetAsUser($client, '/api/user/profile/data', $user);

        self::assertResponseStatus($response, 200);
        self::assertPrivateNoStoreApiCacheHeaders($response);

        $data = self::decodeJson($response);
        foreach ([
            'id',
            'email',
            'isOrganizationAdmin',
        ] as $field) {
            self::assertArrayHasKey($field, $data);
        }

        self::assertSame($adminUser['id'], $data['id']);
        self::assertSame($adminUser['email'], $data['email']);
        self::assertTrue($data['isOrganizationAdmin']);

        foreach (['publicPlayerSlug', 'photo', 'bio'] as $field) {
            if (array_key_exists($field, $data)) {
                self::assertTrue($data[$field] === null || is_string($data[$field]));
            }
        }
        if (array_key_exists('publicPlayerSlug', $data) && is_string($data['publicPlayerSlug'])) {
            self::assertNotSame('', $data['publicPlayerSlug']);
        }
        if (array_key_exists('yearOfBirth', $data)) {
            self::assertTrue($data['yearOfBirth'] === null || is_int($data['yearOfBirth']));
        }
    }
}
