<?php

declare(strict_types=1);

namespace App\ApiResource\UserAdmin;

final readonly class UserAdminProfile
{
    public function __construct(
        public ?string $publicPlayerSlug,
        public bool $isOrganizationAdmin,
    ) {
    }
}
