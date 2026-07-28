<?php

namespace App\State\Provider;

use App\ApiResource\Ranking\GetRanking;
use App\Service\Ranking\RankingServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;

trait ProvidesCachedRankingTrait
{
    /**
     * @throws InvalidArgumentException
     */
    private function provideCachedRanking(
        CacheInterface $cache,
        RankingServiceInterface $rankingService,
        int $organizationId,
        string $cacheKey,
        string $rankingType,
    ): GetRanking {
        return $cache->get($cacheKey, function () use ($rankingService, $organizationId, $rankingType): GetRanking {
            return $rankingService->getRanking($organizationId, $rankingType);
        });
    }
}
