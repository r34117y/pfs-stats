<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class PlayerTournamentSummaryEndpointTest extends ApiEndpointTestCase
{
    public function testGetsPlayerTournamentSummaryFromColdCache(): void
    {
        $client = self::createApiClient();
        $pair = self::devData()->findTournamentPlayerWithGames();

        if ($pair === null) {
            self::markTestSkipped('No tournament/player pair with games exists in the dev database.');
        }

        $response = self::requestJsonGet(
            $client,
            sprintf('/api/tournaments/%d/players/%s/summary', $pair['tournamentId'], $pair['playerSlug']),
        );

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        foreach (['tournamentId', 'playerId', 'playerName', 'tournamentName', 'stats', 'games'] as $field) {
            self::assertArrayHasKey($field, $data);
        }

        self::assertSame($pair['tournamentId'], $data['tournamentId']);
        self::assertSame($pair['playerId'], $data['playerId']);
        self::assertIsString($data['playerName']);
        self::assertNotSame('', $data['playerName']);
        self::assertIsString($data['tournamentName']);
        self::assertNotSame('', $data['tournamentName']);

        self::assertIsArray($data['stats']);
        foreach ([
            'position',
            'rankAchieved',
            'avgOpponentRank',
            'avgPointsPerGame',
            'avgOpponentPointsPerGame',
            'avgPointsPerGameWon',
            'avgOpponentPointsPerGameWon',
            'avgPointsPerGameLost',
            'avgOpponentPointsPerGameLost',
            'avgPointsSum',
            'avgDiffWon',
            'avgDiffLost',
        ] as $field) {
            self::assertArrayHasKey($field, $data['stats']);
            self::assertTrue(is_int($data['stats'][$field]) || is_float($data['stats'][$field]));
        }

        self::assertIsArray($data['games']);
        self::assertNotSame([], $data['games']);

        $firstGame = $data['games'][0];
        self::assertIsArray($firstGame);
        foreach ([
            'round',
            'wasFirstToPlay',
            'result',
            'opponentId',
            'opponentName',
            'achievedRank',
            'points',
            'pointsLost',
            'pointsSum',
            'scalp',
        ] as $field) {
            self::assertArrayHasKey($field, $firstGame);
        }

        self::assertIsInt($firstGame['round']);
        self::assertGreaterThan(0, $firstGame['round']);
        if (array_key_exists('tableNumber', $firstGame)) {
            self::assertTrue($firstGame['tableNumber'] === null || is_int($firstGame['tableNumber']));
        }
        self::assertIsBool($firstGame['wasFirstToPlay']);
        self::assertContains($firstGame['result'], ['win', 'lose', 'draw']);
        self::assertIsInt($firstGame['opponentId']);
        self::assertGreaterThan(0, $firstGame['opponentId']);
        self::assertIsString($firstGame['opponentName']);
        self::assertNotSame('', $firstGame['opponentName']);
        foreach (['achievedRank', 'scalp'] as $field) {
            self::assertTrue(is_int($firstGame[$field]) || is_float($firstGame[$field]));
        }
        foreach (['points', 'pointsLost', 'pointsSum'] as $field) {
            self::assertIsInt($firstGame[$field]);
            self::assertGreaterThanOrEqual(0, $firstGame[$field]);
        }
    }
}
