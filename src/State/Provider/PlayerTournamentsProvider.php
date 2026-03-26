<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerTournaments\PlayerTournaments;
use App\Service\PlayerSlugResolver;
use App\Service\PlayerTournaments\PlayerTournamentsServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class PlayerTournamentsProvider implements ProviderInterface
{
    public function __construct(
        private PlayerTournamentsServiceInterface $playerTournamentsService,
        private PlayerSlugResolver $playerSlugResolver,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerTournaments
    {
        $playerSlug = trim((string) ($uriVariables['slug'] ?? $uriVariables['playerSlug'] ?? ''));
        $playerId = $this->playerSlugResolver->resolveLegacyPlayerId($playerSlug);

        if ($playerSlug === '' || $playerId === null) {
            throw new NotFoundHttpException('Player not found.');
        }

        return $this->cache->get(
            sprintf('api.player_tournaments.%s', $playerSlug),
            fn (): PlayerTournaments => $this->playerTournamentsService->getPlayerTournaments($playerId),
        );
    }
}
