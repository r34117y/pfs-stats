<?php

namespace App\GcgParser\ParsedGcg\Events;

class WithdrawalEvent extends AbstractEvent
{

    public function getType(): string
    {
        return EventTypeEnum::MOVE_TYPE_WITHDRAWAL;
    }
}
