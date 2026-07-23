<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class TournamentsListEndpointTest extends ApiEndpointTestCase
{
    public function testGetsTournamentsListFromColdCache(): void
    {
        $client = self::createApiClient();

        $response = self::requestJsonGet($client, '/api/tournaments');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertArrayHasKey('tournaments', $data);
        self::assertIsArray($data['tournaments']);

        if (!self::devData()->hasDefaultOrganizationTournamentRows()) {
            self::assertSame([], $data['tournaments']);

            return;
        }

        self::assertNotSame([], $data['tournaments']);

        $firstTournament = $data['tournaments'][0];
        self::assertIsArray($firstTournament);
        foreach ([
            'id',
            'name',
            'startDate',
            'tournamentRank',
            'numberOfPlayers',
            'winnerName',
            'winnerId',
        ] as $field) {
            self::assertArrayHasKey($field, $firstTournament);
        }

        self::assertIsInt($firstTournament['id']);
        self::assertGreaterThan(0, $firstTournament['id']);
        self::assertIsString($firstTournament['name']);
        self::assertNotSame('', $firstTournament['name']);
        self::assertIsString($firstTournament['startDate']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$|^unknown$/', $firstTournament['startDate']);
        self::assertIsFloat($firstTournament['tournamentRank']);
        self::assertIsInt($firstTournament['numberOfPlayers']);
        self::assertGreaterThanOrEqual(0, $firstTournament['numberOfPlayers']);
        self::assertIsString($firstTournament['winnerName']);
        self::assertNotSame('', $firstTournament['winnerName']);
        self::assertIsInt($firstTournament['winnerId']);
        self::assertGreaterThan(0, $firstTournament['winnerId']);
    }
}
