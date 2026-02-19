<?php

namespace App\ApiResource\UserProfile;

class UserProfileSaveResponse
{
    public function __construct(
        public string $message,
        public UserProfile $profile,
    ) {
    }
}
