<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class AnnotatedGameDetailsEndpointTest extends ApiEndpointTestCase
{
    public function testGetsAnnotatedGameDetailsFromColdCache(): void
    {
        $client = self::createApiClient();
        $annotatedGameId = self::devData()->findAnnotatedGameId();

        if ($annotatedGameId === null) {
            self::markTestSkipped('No annotated game exists in the dev database.');
        }

        $response = self::requestJsonGet($client, '/api/games/' . $annotatedGameId);

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        foreach ([
            'tournamentId',
            'tournamentName',
            'round',
            'player1Id',
            'player1Name',
            'player2Id',
            'player2Name',
            'data',
            'updated',
            'parsedGcg',
        ] as $field) {
            self::assertArrayHasKey($field, $data);
        }

        [$expectedTournamentId, $expectedRound, $expectedPlayer1Id] = array_map('intval', explode('-', $annotatedGameId));
        self::assertSame($expectedTournamentId, $data['tournamentId']);
        self::assertSame($expectedRound, $data['round']);
        self::assertSame($expectedPlayer1Id, $data['player1Id']);

        self::assertIsString($data['tournamentName']);
        self::assertNotSame('', $data['tournamentName']);
        self::assertIsString($data['player1Name']);
        self::assertNotSame('', $data['player1Name']);
        self::assertIsInt($data['player2Id']);
        self::assertGreaterThan(0, $data['player2Id']);
        self::assertIsString($data['player2Name']);
        self::assertNotSame('', $data['player2Name']);
        self::assertIsString($data['data']);
        self::assertNotSame('', $data['data']);
        self::assertStringContainsString('#player', $data['data']);
        self::assertIsString($data['updated']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['updated']);

        $parsedGcg = $data['parsedGcg'];
        self::assertIsArray($parsedGcg);
        foreach (['meta', 'players', 'events', 'gcg'] as $field) {
            self::assertArrayHasKey($field, $parsedGcg);
        }
        self::assertSame($data['data'], $parsedGcg['gcg']);
        self::assertIsArray($parsedGcg['players']);
        self::assertCount(2, $parsedGcg['players']);
        self::assertIsArray($parsedGcg['events']);
        self::assertNotSame([], $parsedGcg['events']);

        $firstPlayer = $parsedGcg['players'][0];
        self::assertIsArray($firstPlayer);
        foreach (['nick', 'name', 'number'] as $field) {
            self::assertArrayHasKey($field, $firstPlayer);
        }
        self::assertIsString($firstPlayer['nick']);
        self::assertNotSame('', $firstPlayer['nick']);
        self::assertIsString($firstPlayer['name']);
        self::assertNotSame('', $firstPlayer['name']);
        self::assertIsInt($firstPlayer['number']);

        $firstEvent = $parsedGcg['events'][0];
        self::assertIsArray($firstEvent);
        foreach (['type', 'playerNick', 'score', 'totalScore'] as $field) {
            self::assertArrayHasKey($field, $firstEvent);
        }
        self::assertIsString($firstEvent['type']);
        self::assertNotSame('', $firstEvent['type']);
        self::assertIsString($firstEvent['playerNick']);
        self::assertNotSame('', $firstEvent['playerNick']);
        self::assertIsInt($firstEvent['score']);
        self::assertIsInt($firstEvent['totalScore']);
    }
}
