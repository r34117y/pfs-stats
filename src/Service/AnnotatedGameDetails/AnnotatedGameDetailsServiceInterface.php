<?php

namespace App\Service\AnnotatedGameDetails;

use App\ApiResource\AnnotatedGameDetails\AnnotatedGameDetails;

interface AnnotatedGameDetailsServiceInterface
{
    public function getByKey(int $tournamentId, int $round, int $player1Id): AnnotatedGameDetails;
}
