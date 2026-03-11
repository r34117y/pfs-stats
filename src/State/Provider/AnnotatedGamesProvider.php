<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AnnotatedGames\AnnotatedGames;
use App\Service\AnnotatedGames\AnnotatedGamesServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class AnnotatedGamesProvider implements ProviderInterface
{
    public function __construct(
        private AnnotatedGamesServiceInterface $annotatedGamesService,
        private RequestStack $requestStack,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AnnotatedGames
    {
        $request = $this->requestStack->getCurrentRequest();
        $page = (int) ($request?->query->get('page', 1) ?? 1);
        $playerName = $request?->query->get('playerName');
        $tournamentName = $request?->query->get('tournamentName');
        $playerNameFilter = is_string($playerName) ? trim($playerName) : '';
        $tournamentNameFilter = is_string($tournamentName) ? trim($tournamentName) : '';
        $cacheKey = 'api.annotated_games.' . sha1(sprintf('%d|%s|%s', $page, $playerNameFilter, $tournamentNameFilter));

        return $this->cache->get(
            $cacheKey,
            fn (): AnnotatedGames => $this->annotatedGamesService->getAnnotatedGames(
                page: $page,
                playerName: $playerNameFilter !== '' ? $playerNameFilter : null,
                tournamentName: $tournamentNameFilter !== '' ? $tournamentNameFilter : null,
            ),
        );
    }
}
