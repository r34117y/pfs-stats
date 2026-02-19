<?php

namespace App\ApiResource\UserProfile;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\UserProfileProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/user/profile/data',
            description: 'Get current authenticated user profile data.',
            provider: UserProfileProvider::class,
            security: "is_granted('ROLE_USER')"
        ),
    ],
)]
class UserProfile
{
    public function __construct(
        public int $id,
        public string $email,
        public ?int $yearOfBirth,
        public ?string $photo,
    ) {
    }
}
