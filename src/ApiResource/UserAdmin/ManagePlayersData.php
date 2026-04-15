<?php

declare(strict_types=1);

namespace App\ApiResource\UserAdmin;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\ManagePlayersDataProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/user/players/manage/data',
            description: 'Get player management page data for organization admins.',
            security: "is_granted('ROLE_USER')",
            provider: ManagePlayersDataProvider::class,
        ),
    ],
)]
final readonly class ManagePlayersData
{
    /**
     * @param UserAdminOrganization[] $organizations
     */
    public function __construct(
        public UserAdminProfile $profile,
        public string $title,
        public string $description,
        public array $organizations,
        public array $players,
    ) {
    }
}
