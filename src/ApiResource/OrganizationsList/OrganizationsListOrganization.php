<?php

declare(strict_types=1);

namespace App\ApiResource\OrganizationsList;

final readonly class OrganizationsListOrganization
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
    ) {
    }
}
