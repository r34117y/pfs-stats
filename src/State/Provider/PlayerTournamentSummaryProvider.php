<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerTournamentSummary\PlayerTournamentSummary;
use App\Service\PlayerSlugResolver;
use App\Service\PlayerTournamentSummary\PlayerTournamentSummaryServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class PlayerTournamentSummaryProvider implements ProviderInterface
{
    public function __construct(
        private PlayerTournamentSummaryServiceInterface $playerTournamentSummaryService,
        private PlayerSlugResolver $playerSlugResolver,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerTournamentSummary
    {
        $rawTournamentId = $uriVariables['tournamentId'] ?? $uriVariables['id'] ?? null;
        $playerSlug = trim((string) ($uriVariables['playerSlug'] ?? ''));
        $tournamentId = is_numeric($rawTournamentId) ? (int) $rawTournamentId : 0;
        $playerId = $this->playerSlugResolver->resolveLegacyPlayerId($playerSlug);

        if ($tournamentId <= 0 || $playerSlug === '' || $playerId === null) {
            throw new NotFoundHttpException('Invalid tournament or player.');
        }

        return $this->cache->get(
            sprintf('api.player_tournament_summary.%d.%s', $tournamentId, $playerSlug),
            fn (): PlayerTournamentSummary => $this->playerTournamentSummaryService->getSummary($tournamentId, $playerId),
        );
    }
}
