<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerProfile\PlayerProfile;
use App\Service\PlayerProfileService;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class PlayerProfileProvider implements ProviderInterface
{
    public function __construct(
        private PlayerProfileService $playerProfileService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerProfile
    {
        $rawPlayerId = $uriVariables['id'] ?? null;
        $playerId = is_numeric($rawPlayerId) ? (int) $rawPlayerId : 0;

        if ($playerId <= 0) {
            throw new NotFoundHttpException('Player not found.');
        }

        $todayKey = (new DateTimeImmutable('today'))->format('Ymd');

        return $this->cache->get(
            sprintf('api.player_profile.%d.%s', $playerId, $todayKey),
            fn (): PlayerProfile => $this->playerProfileService->getPlayerProfile($playerId),
        );
    }
}
