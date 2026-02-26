<?php

declare(strict_types=1);

namespace App\Tests\Ranking\Domain;

use App\Ranking\Domain\BradleyTerryModel;
use PHPUnit\Framework\TestCase;

final class BradleyTerryModelTest extends TestCase
{
    public function testProducesOrderedSkillsAndPositiveUncertainty(): void
    {
        $games = [];
        $counts = [];

        $append = static function (array &$games, array &$counts, int $p1, int $p2, float $score1): void {
            $games[] = ['player1Id' => $p1, 'player2Id' => $p2, 'score1' => $score1];
            $counts[$p1] = ($counts[$p1] ?? 0) + 1;
            $counts[$p2] = ($counts[$p2] ?? 0) + 1;
        };

        for ($i = 0; $i < 8; $i++) {
            $append($games, $counts, 1, 2, 1.0);
            $append($games, $counts, 1, 3, 1.0);
            $append($games, $counts, 2, 3, 1.0);
        }

        $model = new BradleyTerryModel(
            sigmaPrior: 2.0,
            maxIter: 50,
            tol: 1e-7,
            maxFullCovariancePlayers: 50,
        );
        $fit = $model->fit($games, $counts, 0.95);

        self::assertSame(3, $fit->playerCount);
        self::assertSame(count($games), $fit->gameCount);
        self::assertArrayHasKey(1, $fit->estimatesByPlayer);
        self::assertArrayHasKey(2, $fit->estimatesByPlayer);
        self::assertArrayHasKey(3, $fit->estimatesByPlayer);

        self::assertGreaterThan($fit->estimatesByPlayer[2]->sHat, $fit->estimatesByPlayer[1]->sHat);
        self::assertGreaterThan($fit->estimatesByPlayer[3]->sHat, $fit->estimatesByPlayer[2]->sHat);

        self::assertGreaterThan(0.0, $fit->estimatesByPlayer[1]->se);
        self::assertGreaterThan(0.0, $fit->estimatesByPlayer[2]->se);
        self::assertGreaterThan(0.0, $fit->estimatesByPlayer[3]->se);
    }

    public function testUncertaintyShrinksWithMoreGames(): void
    {
        $model = new BradleyTerryModel(maxIter: 60, maxFullCovariancePlayers: 50);

        [$fewGames, $fewCounts] = $this->buildSample(4);
        [$manyGames, $manyCounts] = $this->buildSample(20);

        $fewFit = $model->fit($fewGames, $fewCounts, 0.95);
        $manyFit = $model->fit($manyGames, $manyCounts, 0.95);

        self::assertGreaterThan($manyFit->estimatesByPlayer[1]->se, $fewFit->estimatesByPlayer[1]->se);
    }

    /**
     * @return array{0: list<array{player1Id: int, player2Id: int, score1: float}>, 1: array<int, int>}
     */
    private function buildSample(int $rounds): array
    {
        $games = [];
        $counts = [];

        for ($i = 0; $i < $rounds; $i++) {
            $games[] = ['player1Id' => 1, 'player2Id' => 2, 'score1' => 1.0];
            $games[] = ['player1Id' => 1, 'player2Id' => 3, 'score1' => 1.0];
            $games[] = ['player1Id' => 2, 'player2Id' => 3, 'score1' => 1.0];

            $counts[1] = ($counts[1] ?? 0) + 2;
            $counts[2] = ($counts[2] ?? 0) + 2;
            $counts[3] = ($counts[3] ?? 0) + 2;
        }

        return [$games, $counts];
    }
}

