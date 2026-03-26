<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerRecords\PlayerRecordsTable;
use App\Service\PlayerRecords\PlayerRecordsServiceInterface;
use App\Service\PlayerSlugResolver;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class PlayerRecordsProvider implements ProviderInterface
{
    public function __construct(
        private PlayerRecordsServiceInterface $playerRecordsService,
        private PlayerSlugResolver $playerSlugResolver,
        private RequestStack $requestStack,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerRecordsTable
    {
        $playerSlug = trim((string) ($uriVariables['slug'] ?? $uriVariables['playerSlug'] ?? ''));
        $playerId = $this->playerSlugResolver->resolveLegacyPlayerId($playerSlug);

        if ($playerSlug === '' || $playerId === null) {
            throw new NotFoundHttpException('Player not found.');
        }

        $recordType = (string) ($operation->getExtraProperties()['recordType'] ?? '');
        $request = $this->requestStack->getCurrentRequest();
        $limit = (int) ($request?->query->get('limit', 10) ?? 10);
        $min = $request?->query->has('min') ? (int) $request->query->get('min') : null;

        $cacheKey = sprintf(
            'api.player_records.%s.%s.%d.%s',
            $playerSlug,
            $recordType,
            $limit,
            $min !== null ? (string) $min : 'null',
        );

        return $this->cache->get(
            $cacheKey,
            fn (): PlayerRecordsTable => $this->playerRecordsService->getRecords($playerId, $recordType, $limit, $min),
        );
    }
}
