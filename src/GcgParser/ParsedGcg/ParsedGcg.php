<?php

namespace App\GcgParser\ParsedGcg;

use App\GcgParser\ParsedGcg\Events\EventInterface;

/**
 * Represents a parsed GCG game file.
 */
class ParsedGcg
{
    private ?string $gcg = null;
    private MetaInfo $meta;
    /** @var Player[] */
    private array $players = [];
    /** @var EventInterface[] */
    private array $events = [];

    public function getMeta(): MetaInfo
    {
        return $this->meta;
    }

    public function setMeta(MetaInfo $meta): void
    {
        $this->meta = $meta;
    }

    /**
     * @return Player[]
     */
    public function getPlayers(): array
    {
        return $this->players;
    }

    /**
     * @param Player[] $players
     */
    public function setPlayers(array $players): void
    {
        foreach ($players as $player) {
            $this->addPlayer($player);
        }
    }

    public function addPlayer(Player $player): void
    {
        $this->players[] = $player;
    }

    /**
     * @return EventInterface[]
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @param EventInterface[] $events
     */
    public function setEvents(array $events): void
    {
        foreach ($events as $event) {
            $this->addEvent($event);
        }
    }

    public function addEvent(EventInterface $event): void
    {
        $this->events[] = $event;
    }

    public function getGcg(): ?string
    {
        return $this->gcg;
    }

    public function setGcg(?string $gcg): void
    {
        $this->gcg = $gcg;
    }
}
