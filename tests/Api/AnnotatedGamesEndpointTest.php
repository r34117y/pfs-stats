<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class AnnotatedGamesEndpointTest extends ApiEndpointTestCase
{
    public function testGetsAnnotatedGamesFromColdCache(): void
    {
        $client = self::createApiClient();

        $response = self::requestJsonGet($client, '/api/annotated-games');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        foreach (['items', 'page', 'pageSize', 'totalItems', 'totalPages'] as $field) {
            self::assertArrayHasKey($field, $data);
        }
        self::assertIsArray($data['items']);
        self::assertSame(1, $data['page']);
        self::assertSame(50, $data['pageSize']);
        self::assertIsInt($data['totalItems']);
        self::assertGreaterThanOrEqual(0, $data['totalItems']);
        self::assertIsInt($data['totalPages']);
        self::assertGreaterThanOrEqual(1, $data['totalPages']);

        if (!self::devData()->hasAnnotatedGameRows()) {
            self::assertSame([], $data['items']);
            self::assertSame(0, $data['totalItems']);

            return;
        }

        self::assertNotSame([], $data['items']);
        self::assertGreaterThan(0, $data['totalItems']);

        $firstGame = $data['items'][0];
        self::assertIsArray($firstGame);
        foreach ([
            'tournamentId',
            'tournamentName',
            'round',
            'player1Id',
            'player1Name',
            'player2Id',
            'player2Name',
        ] as $field) {
            self::assertArrayHasKey($field, $firstGame);
        }

        self::assertIsInt($firstGame['tournamentId']);
        self::assertGreaterThan(0, $firstGame['tournamentId']);
        self::assertIsString($firstGame['tournamentName']);
        self::assertNotSame('', $firstGame['tournamentName']);
        self::assertIsInt($firstGame['round']);
        self::assertGreaterThan(0, $firstGame['round']);
        self::assertIsInt($firstGame['player1Id']);
        self::assertGreaterThan(0, $firstGame['player1Id']);
        self::assertIsString($firstGame['player1Name']);
        self::assertNotSame('', $firstGame['player1Name']);
        self::assertIsInt($firstGame['player2Id']);
        self::assertGreaterThan(0, $firstGame['player2Id']);
        self::assertIsString($firstGame['player2Name']);
        self::assertNotSame('', $firstGame['player2Name']);
    }
}
