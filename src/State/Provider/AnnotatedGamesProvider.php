<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AnnotatedGames\AnnotatedGames;
use App\Service\AnnotatedGamesService;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class AnnotatedGamesProvider implements ProviderInterface
{
    public function __construct(
        private AnnotatedGamesService $annotatedGamesService,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AnnotatedGames
    {
        $request = $this->requestStack->getCurrentRequest();
        $page = (int) ($request?->query->get('page', 1) ?? 1);
        $playerName = $request?->query->get('playerName');
        $tournamentName = $request?->query->get('tournamentName');

        return $this->annotatedGamesService->getAnnotatedGames(
            page: $page,
            playerName: is_string($playerName) ? $playerName : null,
            tournamentName: is_string($tournamentName) ? $tournamentName : null,
        );
    }
}
