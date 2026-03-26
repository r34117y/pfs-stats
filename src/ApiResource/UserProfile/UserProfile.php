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
            security: "is_granted('ROLE_USER')",
            provider: UserProfileProvider::class
        ),
    ],
)]
final readonly class UserProfile
{
    public function __construct(
        public int $id,
        public ?string $publicPlayerSlug,
        public string $email,
        public ?int $yearOfBirth,
        public ?string $photo,
    ) {
    }
}
