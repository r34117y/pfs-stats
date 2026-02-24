<?php

namespace App\GcgParser\ParsedGcg\Events;

final class PlayEvent extends AbstractEvent
{
    private array $words;
    private string $startField;
    public function __construct(
        string $playerNick,
        string $rack,
        int $score,
        int $totalScore,
        string $startField,
        array $words
    ) {
        parent::__construct($playerNick, $rack, $score, $totalScore);
        $this->startField = $startField;
        $this->words = $words;
    }

    public function getStartField(): string
    {
        return $this->startField;
    }

    public function getWords(): array
    {
        return $this->words;
    }

    public function getType(): string
    {
        return EventTypeEnum::MOVE_TYPE_PLAY;
    }
}
