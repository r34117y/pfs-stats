<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Component\HttpFoundation\Request;

final class ApiColdCacheRegressionTest extends ApiEndpointTestCase
{
    public function testCliAndHttpDebugModesShareDatasetVersion(): void
    {
        if (!str_ends_with((string) getenv('REDIS_URL'), '/14')) {
            self::markTestSkipped('Use isolated Redis database 14 for cache publication regression.');
        }
        self::createApiClient();
        $publisher = self::getContainer()->get(\App\Service\DatasetVersionService::class);
        $version = $publisher->bumpVersion();
        $debugKernel = new \App\Kernel('test', true);
        $debugKernel->boot();
        try {
            $reader = new \App\Service\DatasetVersionService($debugKernel->getContainer()->get('cache.app'));
            self::assertSame($version, $reader->getVersion());
        } finally {
            $debugKernel->shutdown();
        }
    }

    public function testDatasetPublicationReplacesWarmOldRankingWithoutWarmup(): void
    {
        if (!str_ends_with((string) getenv('REDIS_URL'), '/14')) { self::markTestSkipped('Use isolated Redis database 14 for cache publication regression.'); }
        $client=self::createApiClient();self::clearApiCache();
        $cache=self::getContainer()->get('app.dataset_cache');
        $version=self::getContainer()->get(\App\Service\DatasetVersionService::class);
        $cache->get('api.ranking.old.v3.21',static fn()=>new \App\ApiResource\Ranking\GetRanking([], 'STALE_IMPORT_CHECK', -1));
        $warm=$client->handle(Request::create('/api/old-rank?org=21','GET',server:['HTTP_ACCEPT'=>'application/ld+json']));
        self::assertResponseStatus($warm,200);
        self::assertSame('STALE_IMPORT_CHECK',self::decodeJson($warm)['lastTournamentName']);
        $version->bumpVersion();
        $fresh=$client->handle(Request::create('/api/old-rank?org=21','GET',server:['HTTP_ACCEPT'=>'application/ld+json']));
        self::assertResponseStatus($fresh,200);self::assertPublicApiCacheHeaders($fresh);
        self::assertNotSame('STALE_IMPORT_CHECK',self::decodeJson($fresh)['lastTournamentName']);
        self::clearApiCache();
    }

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
