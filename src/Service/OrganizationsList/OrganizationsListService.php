<?php

declare(strict_types=1);

namespace App\Service\OrganizationsList;

use App\ApiResource\OrganizationsList\OrganizationsList;
use App\ApiResource\OrganizationsList\OrganizationsListOrganization;
use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use LogicException;

final readonly class OrganizationsListService implements OrganizationsListServiceInterface
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
    ) {
    }

    public function getOrganizationsList(): OrganizationsList
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
