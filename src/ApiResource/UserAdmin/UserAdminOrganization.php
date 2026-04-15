<?php

declare(strict_types=1);

namespace App\ApiResource\UserAdmin;

final readonly class UserAdminOrganization
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
    ) {
    }
}
