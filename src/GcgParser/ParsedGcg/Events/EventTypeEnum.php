<?php

namespace App\GcgParser\ParsedGcg\Events;

class EventTypeEnum
{
    /**
     * Regular play
     */
    public const string MOVE_TYPE_PLAY = 'p';
    public const string MOVE_TYPE_EXCHANGE = 'x';
    /**
     * Intentional or unknown reason pass
     */
    public const string MOVE_TYPE_PASS = 'a';
    /**
     * Last move of each player when points from rack remainder are added (or substracted)
     */
    public const string MOVE_TYPE_ENDGAME = 'e';
    /**
     * Successful challenge - taking the word off the board
     */
    public const string MOVE_TYPE_WITHDRAWAL = 'w';

    public const array ALLOWED_MOVE_TYPES = [
        self::MOVE_TYPE_PLAY,
        self::MOVE_TYPE_EXCHANGE,
        self::MOVE_TYPE_PASS,
        self::MOVE_TYPE_ENDGAME,
        self::MOVE_TYPE_WITHDRAWAL,
    ];
}
