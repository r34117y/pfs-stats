<?php

namespace App\Service\PfsPlayerResolver;

interface PfsPlayerResolverInterface
{
    public function resolve(array $playerRanksByName, int $tournamentId): array;
}
