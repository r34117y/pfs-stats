<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
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

        $request = self::createJsonGetRequest($path, $query);

        return $client->handle($request);
    }

    protected static function requestJsonGetAsUser(KernelInterface $client, string $path, User $user, array $query = []): Response
    {
        self::clearApiCache();

        $session = self::getContainer()->get('session.factory')->createSession();
        $token = new PostAuthenticationToken($user, 'main', $user->getRoles());
        $session->set('_security_main', serialize($token));
        $session->save();

        $request = self::createJsonGetRequest($path, $query, [
            $session->getName() => $session->getId(),
        ]);

        return $client->handle($request);
    }

    /**
     * @param array<string, string> $cookies
     */
    private static function createJsonGetRequest(string $path, array $query = [], array $cookies = []): Request
    {
        return Request::create($path, 'GET', $query, $cookies, server: [
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
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

    protected static function assertPublicApiCacheHeaders(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('public'), (string) $response->headers);
        self::assertFalse($response->headers->hasCacheControlDirective('private'), (string) $response->headers);
        self::assertSame('0', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame('86400', $response->headers->getCacheControlDirective('s-maxage'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('stale-while-revalidate'));
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'), (string) $response->headers);
    }

    protected static function assertPrivateNoStoreApiCacheHeaders(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('private'), (string) $response->headers);
        self::assertFalse($response->headers->hasCacheControlDirective('public'), (string) $response->headers);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), (string) $response->headers);
        self::assertSame('0', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame('0', $response->headers->getCacheControlDirective('s-maxage'));
        self::assertFalse($response->headers->hasCacheControlDirective('stale-while-revalidate'), (string) $response->headers);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function assertRankingResponseShape(array $data, bool $expectRows): void
    {
        self::assertArrayHasKey('rows', $data);
        self::assertIsArray($data['rows']);
        self::assertArrayHasKey('lastTournamentName', $data);
        self::assertTrue($data['lastTournamentName'] === null || is_string($data['lastTournamentName']));
        self::assertArrayHasKey('lastTournamentId', $data);
        self::assertTrue($data['lastTournamentId'] === null || is_int($data['lastTournamentId']));
        if (array_key_exists('previousTournamentId', $data)) {
            self::assertTrue($data['previousTournamentId'] === null || is_int($data['previousTournamentId']));
        }
        if (array_key_exists('nextTournamentId', $data)) {
            self::assertTrue($data['nextTournamentId'] === null || is_int($data['nextTournamentId']));
        }

        if (!$expectRows) {
            self::assertSame([], $data['rows']);

            return;
        }

        self::assertNotSame([], $data['rows']);

        $firstRow = $data['rows'][0];
        self::assertIsArray($firstRow);
        foreach ([
            'position',
            'nameShow',
            'nameAlph',
            'playerId',
            'rank',
            'numberOfGames',
            'slug',
        ] as $field) {
            self::assertArrayHasKey($field, $firstRow);
        }
        self::assertIsInt($firstRow['position']);
        self::assertNotSame('', $firstRow['nameShow']);
        self::assertIsString($firstRow['nameShow']);
        self::assertIsString($firstRow['nameAlph']);
        self::assertIsInt($firstRow['playerId']);
        if (array_key_exists('photo', $firstRow)) {
            self::assertTrue($firstRow['photo'] === null || is_string($firstRow['photo']));
        }
        self::assertIsString($firstRow['rank']);
        self::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $firstRow['rank']);
        self::assertIsInt($firstRow['numberOfGames']);
        if (array_key_exists('rankDelta', $firstRow)) {
            self::assertTrue($firstRow['rankDelta'] === null || is_string($firstRow['rankDelta']));
            if (is_string($firstRow['rankDelta'])) {
                self::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $firstRow['rankDelta']);
            }
        }
        if (array_key_exists('positionDelta', $firstRow)) {
            self::assertTrue(
                $firstRow['positionDelta'] === null
                || is_int($firstRow['positionDelta'])
                || $firstRow['positionDelta'] === '+',
            );
        }
        self::assertNotSame('', $firstRow['slug']);
        self::assertIsString($firstRow['slug']);
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

    protected static function findUser(int $userId): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
