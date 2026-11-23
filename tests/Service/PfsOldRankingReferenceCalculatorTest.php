<?php

declare (strict_types=1);
namespace App\Tests\Service;

use App\Service\PfsImportVerification\OldRankingReferenceCalculator as Ref;
use PHPUnit\Framework\TestCase;
final class PfsOldRankingReferenceCalculatorTest extends TestCase
{
    public function testStartingRanksAreRoundedAndFlooredForEveryPlayerCategory(): void
    {
        self::assertSame(100.0, Ref::beforeEvent(85.54, []));
        self::assertSame(149.0, Ref::beforeEvent(149.49, []));
        self::assertSame(150.0, Ref::beforeEvent(149.5, []));
        self::assertSame(100.0, Ref::beforeEvent(null, [['games' => 32, 'scalps' => 2720]]));
        self::assertSame(150.0, Ref::beforeEvent(null, [['games' => 32, 'scalps' => 4784]]));
        self::assertSame(100.0, Ref::beforeEvent(null, [['games' => 10, 'scalps' => 850]]));
        self::assertSame(111.0, Ref::beforeEvent(null, [['games' => 10, 'scalps' => 1315]]));
    }

    public function testIndependentWeightedWindowAndThresholds(): void
    {
        $r = Ref::lists([1 => [['date' => 20240101, 'games' => 20, 'scalps' => 2400], ['date' => 20260101, 'games' => 10, 'scalps' => 1800]], 2 => [['date' => 20260101, 'games' => 29, 'scalps' => 2900]]], 20260101);
        self::assertSame(140.0, $r['rows'][0]['rankExact']);
        self::assertSame(30, $r['rows'][0]['games']);
        self::assertSame(29, $r['waitingRows'][0]['games']);
    }
    public function testCareerFallbackAndScalpRules(): void
    {
        self::assertSame(100.0, Ref::beforeEvent(null, []));
        self::assertEqualsWithDelta(110, Ref::beforeEvent(null, [['games' => 10, 'scalps' => 1300]]), 0.001);
        self::assertSame(150.0, Ref::beforeEvent(null, [['games' => 20, 'scalps' => 2400], ['games' => 12, 'scalps' => 2400]]));
        self::assertSame([180.0, 100.0], Ref::scalps(180, 100, 400, 300));
        self::assertSame([100.0, 180.0], Ref::scalps(180, 100, 350, 350));
        self::assertSame([50.0, 220.0], Ref::scalps(170, 100, 300, 400));
    }
}
