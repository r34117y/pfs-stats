<?php

declare(strict_types=1);

namespace App\ApiResource\UserAdmin;

final readonly class UserAdminRecentTournamentImport
{
    public function __construct(
        public int $organizationId,
        public string $organizationName,
        public int $tournamentId,
        public string $tournamentName,
        public string $date,
        public ?int $urlId,
    ) {
    }
}
