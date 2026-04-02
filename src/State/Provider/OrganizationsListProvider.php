<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\OrganizationsList\OrganizationsList;
use App\Service\OrganizationsList\OrganizationsListServiceInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class OrganizationsListProvider implements ProviderInterface
{
    public function __construct(
        private OrganizationsListServiceInterface $organizationsListService,
        #[Autowire(service: 'app.dataset_cache')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OrganizationsList
    {
        return $this->cache->get(
            'api.organizations.list',
            fn (): OrganizationsList => $this->organizationsListService->getOrganizationsList(),
        );
    }
}
