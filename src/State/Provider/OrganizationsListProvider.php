<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\OrganizationsList\OrganizationsList;
use App\ApiResource\OrganizationsList\OrganizationsListOrganization;
use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use LogicException;

final readonly class OrganizationsListProvider implements ProviderInterface
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OrganizationsList
    {
        $organizations = array_map(
            static function (Organization $organization): OrganizationsListOrganization {
                $id = $organization->getId();
                if ($id === null) {
                    throw new LogicException('Organization ID cannot be null.');
                }

                return new OrganizationsListOrganization(
                    id: $id,
                    code: $organization->getCode(),
                    name: $organization->getName(),
                );
            },
            $this->organizationRepository->findAllOrderedByName(),
        );

        return new OrganizationsList($organizations);
    }
}
