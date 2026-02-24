<?php

namespace App\GcgParser\ParsedGcg;

final readonly class Player {
    public string $nick;
    public string $name;
    public int $number;
    public function __construct($nick, $name, $number) {
        $this->nick = $nick;
        $this->name = $name;
        $this->number = $number;
    }
}
