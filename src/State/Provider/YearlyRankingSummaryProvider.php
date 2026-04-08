<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats\YearlyRankingSummary;
use App\Service\Stats\StatsServiceInterface;
use DateTimeImmutable;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class YearlyRankingSummaryProvider implements ProviderInterface
{
    use ResolvesOrganizationIdFromRequestTrait;
    public function __construct(
        private StatsServiceInterface $statsService,
        private RequestStack $requestStack,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): YearlyRankingSummary
    {
        $defaultYear = (int) (new DateTimeImmutable('today'))->modify('-1 year')->format('Y');
        $requestedYear = $this->requestStack->getCurrentRequest()?->query->get('year');
        $year = is_numeric($requestedYear) ? (int) $requestedYear : $defaultYear;
        if ($year < 1990 || $year > 2100) {
            $year = $defaultYear;
        }
        $orgId = $this->resolveOrganizationId($uriVariables, $this->requestStack);
        $cacheKey = sprintf('api.stats.yearly_ranking_summary.v2.%d.%d', $year, $orgId);

        return $this->cache->get(
            $cacheKey,
            fn (): YearlyRankingSummary => $this->statsService->getYearlyRankingSummary($year, $orgId),
        );
    }
}
