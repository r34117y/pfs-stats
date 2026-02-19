<?php

namespace App\ApiResource\UserProfile;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\UserProfilePhotoUploadProcessor;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/user/profile/photo/upload',
            inputFormats: ['multipart' => ['multipart/form-data']],
            description: 'Upload and compress profile photo for the authenticated user.',
            security: "is_granted('ROLE_USER')",
            output: UserProfilePhotoUploadResponse::class,
            read: false,
            deserialize: false,
            processor: UserProfilePhotoUploadProcessor::class
        ),
    ],
)]
class UserProfilePhotoUpload
{
}
