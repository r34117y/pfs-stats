<?php

declare(strict_types=1);

namespace App\ApiResource\UserAdmin;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AddTournamentResultsDataProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/user/tournament-results/add/data',
            description: 'Get tournament results import page data for organization admins.',
            security: "is_granted('ROLE_USER')",
            provider: AddTournamentResultsDataProvider::class,
        ),
    ],
)]
final readonly class AddTournamentResultsData
{
    /**
     * @param UserAdminOrganization[] $organizations
     * @param UserAdminRecentTournamentImport[] $recentImports
     */
    public function __construct(
        public UserAdminProfile $profile,
        public string $title,
        public array $organizations,
        public array $recentImports,
    ) {
    }
}
