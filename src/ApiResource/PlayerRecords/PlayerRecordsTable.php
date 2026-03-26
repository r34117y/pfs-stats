<?php

namespace App\ApiResource\PlayerRecords;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\PlayerRecordsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/players/{slug}/records/most-points',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'most-points']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/least-points',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'least-points']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/points-highest-sum',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'points-highest-sum']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/points-lowest-sum',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'points-lowest-sum']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/opponent-most-points',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'opponent-most-points']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/opponent-least-points',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'opponent-least-points']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/highest-win',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'highest-win']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/highest-lose',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'highest-lose']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/highest-draw',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'highest-draw']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/lost-with-most-points',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'lost-with-most-points']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/won-with-least-points',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'won-with-least-points']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/won-with-most-points-by-opponent',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'won-with-most-points-by-opponent']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/lost-with-least-points-by-opponent',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'lost-with-least-points-by-opponent']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/win-streak',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'win-streak']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/lose-streak',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'lose-streak']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/streak-by-points',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'streak-by-points']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/streak-by-sum',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'streak-by-sum']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/win-streak-by-player',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'win-streak-by-player']
        ),
        new Get(
            uriTemplate: '/players/{slug}/records/lose-streak-by-player',
            provider: PlayerRecordsProvider::class,
            extraProperties: ['recordType' => 'lose-streak-by-player']
        ),
    ],
)]
class PlayerRecordsTable
{
    /**
     * @param PlayerRecordsRow[] $rows
     */
    public function __construct(
        public string $recordType,
        public array $rows,
    ) {
    }
}
