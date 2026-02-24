<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AnnotatedGameDetails\AnnotatedGameDetails;
use App\Service\AnnotatedGameDetailsService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class AnnotatedGameDetailsProvider implements ProviderInterface
{
    public function __construct(
        private AnnotatedGameDetailsService $annotatedGameDetailsService,
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

        return $this->annotatedGameDetailsService->getByKey($tournamentId, $round, $player1Id);
    }
}
