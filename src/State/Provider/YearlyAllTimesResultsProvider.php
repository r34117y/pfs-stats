<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\YearlyAllTimesResults;
use App\Service\Stats\StatsServiceInterface;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class YearlyAllTimesResultsProvider implements ProviderInterface
{
    public function __construct(
        private StatsServiceInterface $statsService,
        private RequestStack $requestStack,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): YearlyAllTimesResults
    {
        $defaultYear = (int) (new DateTimeImmutable('today'))->modify('-1 year')->format('Y');
        $requestedYear = $this->requestStack->getCurrentRequest()?->query->get('year');
        $year = is_numeric($requestedYear) ? (int) $requestedYear : $defaultYear;
        if ($year < 1990 || $year > 2100) {
            $year = $defaultYear;
        }

        $cacheKey = sprintf('api.stats.yearly_all_times_results.v1.%d', $year);

        return $this->cache->get(
            $cacheKey,
            fn (): YearlyAllTimesResults => new YearlyAllTimesResults(
                $this->statsService->getYearlyAllTimesResults($year)->rows
            ),
        );
    }
}
