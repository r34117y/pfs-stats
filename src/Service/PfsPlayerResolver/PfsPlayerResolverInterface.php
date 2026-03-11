<?php

namespace App\Service;

interface PfsPlayerResolverInterface
{
    public function resolve(array $playerRanksByName, int $tournamentId): array;
}
