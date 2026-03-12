<?php

declare(strict_types=1);

namespace App\ApiResource\GamePhoto;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\GamePhotoUploadProcessor;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/tournament-games/{gameId}/photos',
            inputFormats: ['multipart' => ['multipart/form-data']],
            description: 'Upload a photo for a tournament game. Only participating players can upload.',
            security: "is_granted('ROLE_USER')",
            output: GamePhotoUploadResponse::class,
            read: false,
            deserialize: false,
            processor: GamePhotoUploadProcessor::class
        ),
    ],
)]
final class GamePhotoUpload
{
}
