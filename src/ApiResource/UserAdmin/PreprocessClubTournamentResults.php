<?php

declare(strict_types=1);

namespace App\ApiResource\UserAdmin;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\PreprocessClubTournamentResultsProcessor;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/user/tournament-results/preprocess-club-results',
            inputFormats: ['multipart' => ['multipart/form-data']],
            description: 'Parse uploaded club tournament results and render an import preview.',
            security: "is_granted('ROLE_USER')",
            output: false,
            read: false,
            deserialize: false,
            processor: PreprocessClubTournamentResultsProcessor::class,
        ),
    ],
)]
final readonly class PreprocessClubTournamentResults
{
}
