<?php

namespace App\GcgParser\ParsedGcg\Events;

abstract class AbstractEvent implements EventInterface
{
    protected string $playerNick;
    protected string $rack;
    protected int $score;
    protected int $totalScore;
    protected ?string $note = null;

    public function __construct(
        string $playerNick,
        string $rack,
        int $score,
        int $totalScore
    ) {
        $this->playerNick = $playerNick;
        $this->rack = $rack;
        $this->score = $score;
        $this->totalScore = $totalScore;
    }

    abstract public function getType(): string;

    public function getPlayerNick(): string
    {
        return $this->playerNick;
    }

    public function getRack(): string
    {
        return $this->rack;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getTotalScore(): int
    {
        return $this->totalScore;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }
}
