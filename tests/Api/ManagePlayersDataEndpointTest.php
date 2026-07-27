<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class ManagePlayersDataEndpointTest extends ApiEndpointTestCase
{
    public function testGetsManagePlayersDataForOrganizationAdmin(): void
    {
        $client = self::createApiClient();
        $adminUser = self::devData()->findOrganizationAdminUser();

        if ($adminUser === null) {
            self::markTestSkipped('No organization-admin user exists in the dev database.');
        }

        $response = self::requestJsonGetAsUser(
            $client,
            '/api/user/players/manage/data',
            self::findUser($adminUser['id']),
        );

        self::assertResponseStatus($response, 200);
        self::assertTrue($response->headers->hasCacheControlDirective('private'), (string) $response->headers);
        self::assertFalse($response->headers->hasCacheControlDirective('public'), (string) $response->headers);
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'), (string) $response->headers);
        self::assertSame('0', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame('86400', $response->headers->getCacheControlDirective('s-maxage'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('stale-while-revalidate'));

        $data = self::decodeJson($response);
        foreach (['profile', 'title', 'description', 'organizations', 'players'] as $field) {
            self::assertArrayHasKey($field, $data);
        }

        self::assertIsString($data['title']);
        self::assertNotSame('', $data['title']);
        self::assertIsString($data['description']);
        self::assertNotSame('', $data['description']);
        self::assertSame([], $data['players']);

        self::assertIsArray($data['profile']);
        self::assertArrayHasKey('isOrganizationAdmin', $data['profile']);
        self::assertTrue($data['profile']['isOrganizationAdmin']);
        if (array_key_exists('publicPlayerSlug', $data['profile'])) {
            self::assertTrue($data['profile']['publicPlayerSlug'] === null || is_string($data['profile']['publicPlayerSlug']));
        }

        self::assertIsArray($data['organizations']);
        self::assertNotSame([], $data['organizations']);

        $organization = $data['organizations'][0];
        self::assertIsArray($organization);
        foreach (['id', 'code', 'name'] as $field) {
            self::assertArrayHasKey($field, $organization);
        }
        self::assertSame($adminUser['organizationId'], $organization['id']);
        self::assertIsString($organization['code']);
        self::assertNotSame('', $organization['code']);
        self::assertIsString($organization['name']);
        self::assertNotSame('', $organization['name']);
    }
}
