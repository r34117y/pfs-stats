<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerProfile\PlayerProfile;
use App\Service\PlayerProfileService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PlayerProfileProvider implements ProviderInterface
{
    public function __construct(
        private PlayerProfileService $playerProfileService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerProfile
    {
        $rawPlayerId = $uriVariables['id'] ?? null;
        $playerId = is_numeric($rawPlayerId) ? (int) $rawPlayerId : 0;

        if ($playerId <= 0) {
            throw new NotFoundHttpException('Player not found.');
        }

        return $this->playerProfileService->getPlayerProfile($playerId);
    }
}
