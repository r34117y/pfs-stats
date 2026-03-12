<?php

declare(strict_types=1);

namespace App\ApiResource\GamePhoto;

final class GamePhotoUploadResponse
{
    public function __construct(
        public string $message,
        public int $photoId,
        public int $gameId,
        public string $category,
        public string $photoUrl,
        public string $uploadedAt,
    ) {
    }
}
