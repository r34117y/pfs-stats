<?php

namespace App\GcgParser\ParsedGcg\Events;

use App\Domain\Scrabble\Enum\EventTypeEnum;

class PassEvent extends AbstractEvent
{
    private string $reason;

    public function __construct(
        string $playerNick,
        string $rack,
        int    $score,
        int    $totalScore,
        string $reason
    )
    {
        parent::__construct($playerNick, $rack, $score, $totalScore);
        $this->reason = $reason;
    }

    public function getType(): string
    {
        return EventTypeEnum::MOVE_TYPE_PASS;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
