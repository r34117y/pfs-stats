<?php

declare(strict_types=1);

namespace App\ApiResource\UserAdmin;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\ImportClubTournamentResultsProcessor;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/user/tournament-results/import-club-results',
            inputFormats: ['multipart' => ['multipart/form-data']],
            description: 'Import uploaded club tournament results.',
            security: "is_granted('ROLE_USER')",
            output: false,
            read: false,
            deserialize: false,
            processor: ImportClubTournamentResultsProcessor::class,
        ),
    ],
)]
final readonly class ImportClubTournamentResults
{
}
