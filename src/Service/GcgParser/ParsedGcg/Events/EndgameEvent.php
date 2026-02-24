<?php

namespace App\GcgParser\ParsedGcg\Events;

use App\Domain\Scrabble\Enum\EventTypeEnum;

/**
 * This class represents the final two moves of a Scrabble game,
 * during which the total point value of the tiles remaining on the rack
 * of the player who did not finish first is added to the score
 * of the player who used all their tiles first.
 * Depending on local rules, the first player to finish may either receive
 * double the value of the opponent’s remaining tiles,
 * or receive the single value while the opponent loses that amount from their score.
 */
class EndgameEvent extends AbstractEvent
{
    public function getType(): string
    {
        return EventTypeEnum::MOVE_TYPE_ENDGAME;
    }
}
