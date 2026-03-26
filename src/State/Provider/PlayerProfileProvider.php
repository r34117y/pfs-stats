<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerProfile\PlayerProfile;
use App\Service\PlayerProfile\PlayerProfileServiceInterface;
use App\Service\PlayerSlugResolver;
use DateTimeImmutable;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class PlayerProfileProvider implements ProviderInterface
{
    public function __construct(
        private PlayerProfileServiceInterface $playerProfileService,
        private PlayerSlugResolver $playerSlugResolver,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerProfile
    {
        $playerSlug = trim((string) ($uriVariables['slug'] ?? $uriVariables['playerSlug'] ?? ''));
        $playerId = $this->playerSlugResolver->resolveLegacyPlayerId($playerSlug);

        if ($playerSlug === '' || $playerId === null) {
            throw new NotFoundHttpException('Player not found.');
        }

        $todayKey = (new DateTimeImmutable('today'))->format('Ymd');

        return $this->cache->get(
            sprintf('api.player_profile.%s.%s', $playerSlug, $todayKey),
            fn (): PlayerProfile => $this->playerProfileService->getPlayerProfile($playerId),
        );
    }
}
