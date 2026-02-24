<?php

namespace App\GcgParser\ParsedGcg\Events;

use App\Domain\Scrabble\Enum\EventTypeEnum;

class ExchangeEvent extends AbstractEvent
{
    /**
     * Exchanged tiles
     */
    private string $exchanged;

    public function __construct(
        string $playerNick,
        string $rack,
        int $score,
        int $totalScore,
        string $exchanged
    ) {
        parent::__construct($playerNick, $rack, $score, $totalScore);
        $this->exchanged = $exchanged;
    }

    public function getType(): string
    {
        return EventTypeEnum::MOVE_TYPE_EXCHANGE;
    }

    public function getExchanged(): string
    {
        return $this->exchanged;
    }
}
