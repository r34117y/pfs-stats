<?php

declare(strict_types=1);

namespace App\State\Provider;

use Symfony\Component\HttpFoundation\RequestStack;

trait ResolvesOrganizationIdFromRequestTrait
{
    private function resolveOrganizationId(array $uriVariables, RequestStack $requestStack): int
    {
        $orgIdFromUri = $uriVariables['org'] ?? null;
        if (is_numeric($orgIdFromUri)) {
            return (int) $orgIdFromUri;
        }

        $orgIdFromQuery = $requestStack->getCurrentRequest()?->query->get('org');
        if (is_numeric($orgIdFromQuery)) {
            return (int) $orgIdFromQuery;
        }

        return 21;
    }
}
