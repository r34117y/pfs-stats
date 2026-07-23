<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class RankingEndpointTest extends ApiEndpointTestCase
{
    public function testGetsCurrentRankingFromColdCache(): void
    {
        $client = self::createApiClient();

        $response = self::requestJsonGet($client, '/api/ranking');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);

        $data = self::decodeJson($response);
        self::assertRankingResponseShape($data, self::devData()->hasRankingRows());
    }
}
