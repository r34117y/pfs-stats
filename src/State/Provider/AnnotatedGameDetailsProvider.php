<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AnnotatedGameDetails\AnnotatedGameDetails;
use App\Service\AnnotatedGameDetails\AnnotatedGameDetailsService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class AnnotatedGameDetailsProvider implements ProviderInterface
{
    public function __construct(
        private AnnotatedGameDetailsService $annotatedGameDetailsService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AnnotatedGameDetails
    {
        $id = (string) ($uriVariables['id'] ?? '');

        if (!preg_match('/^(?<tour>\d+)-(?<round>\d+)-(?<player1>\d+)$/', $id, $matches)) {
            throw new NotFoundHttpException('Invalid annotated game key. Expected format: {tournamentId}-{round}-{player1Id}.');
        }

        $tournamentId = (int) $matches['tour'];
        $round = (int) $matches['round'];
        $player1Id = (int) $matches['player1'];

        if ($tournamentId <= 0 || $round <= 0 || $player1Id <= 0) {
            throw new NotFoundHttpException('Invalid annotated game key values.');
        }

        return $this->cache->get(
            sprintf('api.annotated_game_details.%d.%d.%d', $tournamentId, $round, $player1Id),
            fn (): AnnotatedGameDetails => $this->annotatedGameDetailsService->getByKey($tournamentId, $round, $player1Id),
        );
    }
}
