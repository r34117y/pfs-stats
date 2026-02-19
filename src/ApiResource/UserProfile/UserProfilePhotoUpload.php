<?php

namespace App\ApiResource\UserProfile;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\UserProfilePhotoUploadProcessor;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/user/profile/photo/upload',
            description: 'Upload profile photo to a temporary dummy endpoint.',
            processor: UserProfilePhotoUploadProcessor::class,
            security: "is_granted('ROLE_USER')",
            deserialize: false,
            inputFormats: ['multipart' => ['multipart/form-data']],
            output: UserProfilePhotoUploadResponse::class,
            read: false
        ),
    ],
)]
class UserProfilePhotoUpload
{
}
