<?php

namespace App\GcgParser\ParsedGcg\Events;

use App\Domain\Scrabble\Enum\EventTypeEnum;

class WithdrawalEvent extends AbstractEvent
{

    public function getType(): string
    {
        return EventTypeEnum::MOVE_TYPE_WITHDRAWAL;
    }
}
