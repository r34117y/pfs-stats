<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Psr\Cache\CacheItemPoolInterface;

final class ApiEndpointTestCaseTest extends ApiEndpointTestCase
{
    public function testClearsCacheAndRequestsJsonEndpoint(): void
    {
        $client = self::createApiClient();
        $cache = self::getApiCachePool();

        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);

        $item = $cache->getItem('tests.api_endpoint_test_case.probe');
        $item->set('cached-value');
        self::assertTrue($cache->save($item));
        self::assertTrue($cache->getItem('tests.api_endpoint_test_case.probe')->isHit());

        self::clearApiCache();

        self::assertFalse($cache->getItem('tests.api_endpoint_test_case.probe')->isHit());

        $response = self::requestJsonGet($client, '/api/organizations');

        self::assertResponseStatus($response, 200);
        self::assertArrayHasKey('organizations', self::decodeJson($response));
    }
}
