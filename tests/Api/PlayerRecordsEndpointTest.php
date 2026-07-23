<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;

final class PlayerRecordsEndpointTest extends ApiEndpointTestCase
{
    #[DataProvider('recordTypes')]
    public function testGetsPlayerRecordTableFromColdCache(string $recordType): void
    {
        $client = self::createApiClient();
        $player = self::devData()->findPfsPlayerWithTournamentGames();

        if ($player === null) {
            self::markTestSkipped('No PFS player with tournament games exists in the dev database.');
        }

        $response = self::requestJsonGet($client, sprintf('/api/players/%s/records/%s', $player['slug'], $recordType));

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('recordType', $data);
        self::assertSame($recordType, $data['recordType']);
        self::assertArrayHasKey('rows', $data);
        self::assertIsArray($data['rows']);

        if ($data['rows'] === []) {
            self::assertSame([], $data['rows']);

            return;
        }

        $firstRow = $data['rows'][0];
        self::assertIsArray($firstRow);
        self::assertArrayHasKey('position', $firstRow);
        self::assertIsInt($firstRow['position']);
        self::assertGreaterThan(0, $firstRow['position']);

        $optionalFields = [
            'points' => 'int',
            'opponent' => 'string',
            'score' => 'string',
            'tournament' => 'string',
            'streak' => 'int',
            'tournaments' => 'string',
            'firstTournament' => 'string',
            'lastTournament' => 'string',
        ];

        foreach ($optionalFields as $field => $type) {
            if (!array_key_exists($field, $firstRow)) {
                continue;
            }

            if ($type === 'int') {
                self::assertTrue($firstRow[$field] === null || is_int($firstRow[$field]));
            } else {
                self::assertTrue($firstRow[$field] === null || is_string($firstRow[$field]));
            }
        }

        if (array_key_exists('score', $firstRow) && is_string($firstRow['score'])) {
            self::assertMatchesRegularExpression('/^\d+:\d+$/', $firstRow['score']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recordTypes(): iterable
    {
        foreach ([
            'most-points',
            'least-points',
            'points-highest-sum',
            'points-lowest-sum',
            'opponent-most-points',
            'opponent-least-points',
            'highest-win',
            'highest-lose',
            'highest-draw',
            'lost-with-most-points',
            'won-with-least-points',
            'won-with-most-points-by-opponent',
            'lost-with-least-points-by-opponent',
            'win-streak',
            'lose-streak',
            'streak-by-points',
            'streak-by-sum',
            'win-streak-by-player',
            'lose-streak-by-player',
        ] as $recordType) {
            yield $recordType => [$recordType];
        }
    }
}
