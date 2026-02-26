<?php

declare(strict_types=1);

namespace App\Tests\Ranking\Domain;

use App\Ranking\Domain\EloRatingModel;
use PHPUnit\Framework\TestCase;

final class EloRatingModelTest extends TestCase
{
    public function testEloUpdateAgainstEqualOpponentAfterWin(): void
    {
        $model = new EloRatingModel(kFactor: 20.0, initial: 0.0);
        [$a, $b] = $model->updateRatings(0.0, 0.0, 1.0);

        self::assertEqualsWithDelta(10.0, $a, 0.0001);
        self::assertEqualsWithDelta(-10.0, $b, 0.0001);
    }
}

