<?php

namespace App\ApiResource\UserProfile;

class UserProfilePhotoUploadResponse
{
    public function __construct(
        public string $message,
        public string $photoUrl,
    ) {
    }
}
