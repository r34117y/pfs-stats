<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerProfile\PlayerProfile;
use App\Service\PlayerProfile\PlayerProfileServiceInterface;
use DateTimeImmutable;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class PlayerProfileProvider implements ProviderInterface
{
    public function __construct(
        private PlayerProfileServiceInterface $playerProfileService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerProfile
    {
        $playerSlug = trim((string) ($uriVariables['slug'] ?? ''));

        if ($playerSlug === '') {
            throw new NotFoundHttpException('Player not found.');
        }

        return $this->cache->get(
            sprintf('api.player_profile.%s', $playerSlug),
            fn (): PlayerProfile => $this->playerProfileService->getPlayerProfile($playerSlug),
        );
    }
}
