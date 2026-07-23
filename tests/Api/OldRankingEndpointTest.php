<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class OldRankingEndpointTest extends ApiEndpointTestCase
{
    public function testGetsOldMethodCurrentRankingFromColdCache(): void
    {
        $client = self::createApiClient();

        $response = self::requestJsonGet($client, '/api/old-rank');

        self::assertResponseStatus($response, 200);
        self::assertPublicApiCacheHeaders($response);
        self::assertRankingResponseShape(self::decodeJson($response), self::devData()->hasRankingRows());
    }
}
