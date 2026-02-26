<?php

declare(strict_types=1);

namespace App\Tests\Ranking\Domain;

use App\Ranking\Domain\Glicko2OpponentResult;
use App\Ranking\Domain\Glicko2Service;
use App\Ranking\Domain\PlayerRatingState;
use PHPUnit\Framework\TestCase;

final class Glicko2ServiceTest extends TestCase
{
    public function testKnownReferenceScenario(): void
    {
        $service = new Glicko2Service(tau: 0.5);
        $state = new PlayerRatingState(1500.0, 200.0, 0.06, null);

        $next = $service->updateAfterRatingPeriod($state, [
            new Glicko2OpponentResult(1400.0, 30.0, 1.0),
            new Glicko2OpponentResult(1550.0, 100.0, 0.0),
            new Glicko2OpponentResult(1700.0, 300.0, 0.0),
        ]);

        self::assertEqualsWithDelta(1464.06, $next->rating, 0.25);
        self::assertEqualsWithDelta(151.52, $next->rd, 0.25);
        self::assertEqualsWithDelta(0.05999, $next->sigma, 0.0002);
    }
}
