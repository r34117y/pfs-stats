<?php

declare(strict_types=1);

namespace App\Service\OrganizationsList;

use App\ApiResource\OrganizationsList\OrganizationsList;

interface OrganizationsListServiceInterface
{
    public function getOrganizationsList(): OrganizationsList;
}
