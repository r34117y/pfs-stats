<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class AddTournamentResultsDataEndpointTest extends ApiEndpointTestCase
{
    public function testGetsAddTournamentResultsDataForOrganizationAdmin(): void
    {
        $client = self::createApiClient();
        $adminUser = self::devData()->findOrganizationAdminUser();

        if ($adminUser === null) {
            self::markTestSkipped('No organization-admin user exists in the dev database.');
        }

        $response = self::requestJsonGetAsUser(
            $client,
            '/api/user/tournament-results/add/data',
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
        foreach (['profile', 'title', 'organizations', 'recentImports'] as $field) {
            self::assertArrayHasKey($field, $data);
        }

        self::assertIsString($data['title']);
        self::assertNotSame('', $data['title']);

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
        self::assertSame($adminUser['organizationId'], $organization['id'] ?? null);
        foreach (['code', 'name'] as $field) {
            self::assertArrayHasKey($field, $organization);
            self::assertIsString($organization[$field]);
            self::assertNotSame('', $organization[$field]);
        }

        self::assertIsArray($data['recentImports']);
        if ($data['recentImports'] === []) {
            return;
        }

        $recentImport = $data['recentImports'][0];
        self::assertIsArray($recentImport);
        foreach (['organizationId', 'tournamentId'] as $field) {
            self::assertArrayHasKey($field, $recentImport);
            self::assertIsInt($recentImport[$field]);
            self::assertGreaterThan(0, $recentImport[$field]);
        }
        foreach (['organizationName', 'tournamentName', 'date'] as $field) {
            self::assertArrayHasKey($field, $recentImport);
            self::assertIsString($recentImport[$field]);
            self::assertNotSame('', $recentImport[$field]);
        }
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$|^\d{8}$/', $recentImport['date']);
        if (array_key_exists('urlId', $recentImport)) {
            self::assertTrue($recentImport['urlId'] === null || is_int($recentImport['urlId']));
        }
    }
}
