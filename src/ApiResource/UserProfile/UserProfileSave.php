<?php

namespace App\ApiResource\UserProfile;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\UserProfileSaveProcessor;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/user/profile/save',
            description: 'Save editable profile data for the authenticated user.',
            processor: UserProfileSaveProcessor::class,
            security: "is_granted('ROLE_USER')",
            output: UserProfileSaveResponse::class,
            read: false
        ),
    ],
)]
class UserProfileSave
{
    public function __construct(
        public string $email = '',
        public ?string $dateOfBirth = null,
    ) {
    }
}
