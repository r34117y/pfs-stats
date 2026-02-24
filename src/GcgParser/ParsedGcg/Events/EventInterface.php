<?php

namespace App\GcgParser\ParsedGcg\Events;

interface EventInterface
{
    public function getPlayerNick(): string;

    public function getScore(): int;
    public function getTotalScore(): int;
    public function getType(): string;
    public function getRack(): string;
    public function setNote(?string $note): void;
    public function getNote(): ?string;
}
