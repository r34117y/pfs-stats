<?php

namespace App\GcgParser\ParsedGcg\Events;

final class ChallengeEvent extends AbstractEvent
{
    public function getType(): string
    {
        return 'challenge';
    }
}
