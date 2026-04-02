<?php

declare(strict_types=1);

namespace App\ApiResource\OrganizationsList;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\OrganizationsListProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/organizations',
            description: 'Get organizations list.',
            provider: OrganizationsListProvider::class,
        ),
    ],
)]
final readonly class OrganizationsList
{
    /**
     * @param OrganizationsListOrganization[] $organizations
     */
    public function __construct(
        public array $organizations,
    ) {
    }
}
