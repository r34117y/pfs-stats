<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Cache\CacheInterface;

abstract class ApiEndpointTestCase extends KernelTestCase
{
    protected static function createApiClient(): KernelInterface
    {
        return self::bootKernel();
    }

    protected static function requestJsonGet(KernelInterface $client, string $path, array $query = []): Response
    {
        self::clearApiCache();

        $request = Request::create($path, 'GET', $query, server: [
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);

        return $client->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decodeJson(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    protected static function assertResponseStatus(Response $response, int $expectedStatusCode): void
    {
        self::assertSame($expectedStatusCode, $response->getStatusCode(), (string) $response->getContent());
    }

    protected static function clearApiCache(): void
    {
        $cache = self::getApiCachePool();

        if (!method_exists($cache, 'clear')) {
            self::fail('The cache.app service must support clear() for cold-cache endpoint tests.');
        }

        self::assertTrue($cache->clear(), 'Failed to clear cache.app before API request.');
    }

    protected static function getApiCachePool(): CacheInterface
    {
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheInterface::class, $cache);

        return $cache;
    }

    protected static function devData(): DevDataLookup
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return new DevDataLookup($connection);
    }
}
