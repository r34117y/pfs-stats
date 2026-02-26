<?php

declare(strict_types=1);

namespace App\Tests\Ranking\Application;

use App\Ranking\Application\CalibrateCiService;
use App\Ranking\Application\CiCalibrationConfig;
use App\Ranking\Domain\WindowDefinition;
use App\Ranking\Infrastructure\GameRecord;
use App\Ranking\Infrastructure\GamesDataSource;
use PHPUnit\Framework\TestCase;

final class CalibrateCiServiceTest extends TestCase
{
    public function testDetectsInflatedTopSignalOnSyntheticData(): void
    {
        $start = new \DateTimeImmutable('2020-01-01');
        $end = new \DateTimeImmutable('2021-12-31');

        $games = [
            new GameRecord(1, new \DateTimeImmutable('2020-01-10'), 1, 1, 2, 420, 300),
            new GameRecord(1, new \DateTimeImmutable('2020-01-11'), 2, 1, 2, 410, 320),
            new GameRecord(1, new \DateTimeImmutable('2020-01-12'), 3, 1, 3, 260, 430),
            new GameRecord(1, new \DateTimeImmutable('2020-01-13'), 4, 1, 3, 250, 440),
            new GameRecord(1, new \DateTimeImmutable('2020-01-14'), 5, 1, 3, 240, 450),
            new GameRecord(1, new \DateTimeImmutable('2020-01-15'), 6, 1, 3, 230, 460),
            new GameRecord(1, new \DateTimeImmutable('2020-01-16'), 7, 2, 3, 220, 470),
            new GameRecord(1, new \DateTimeImmutable('2020-01-17'), 8, 2, 3, 210, 480),
        ];

        $dataSource = new class($start, $end, $games) implements GamesDataSource {
            /**
             * @param list<GameRecord> $games
             */
            public function __construct(
                private readonly \DateTimeImmutable $start,
                private readonly \DateTimeImmutable $end,
                private readonly array $games,
            ) {
            }

            public function findDateBounds(): ?array
            {
                return ['start' => $this->start, 'end' => $this->end];
            }

            public function streamWindowGames(WindowDefinition $window): iterable
            {
                foreach ($this->games as $game) {
                    if ($game->playedAt < $window->start || $game->playedAt > $window->end) {
                        continue;
                    }
                    yield $game;
                }
            }
        };

        $service = new CalibrateCiService($dataSource);
        $report = $service->calibrate(new CiCalibrationConfig(
            start: $start,
            end: $end,
            step: new \DateInterval('P1Y'),
            window: new \DateInterval('P2Y'),
            topK: 1,
            nEarly: [2],
            stableGames: 4,
            alphas: [0.05],
            ciLevels: [0.95],
            sigmaPrior: 2.0,
            maxIter: 40,
            tol: 1e-6,
            wGrid: [0.8, 1.2, 1.6, 2.0],
            minGamesForPlayer: 1,
            seed: 1234,
            outDir: 'var/reports/pfs-ci-calibration',
            formats: ['json'],
            maxWindows: 0,
            deltaRank: 1,
            deltaSkill: 0.0,
            maxFullCovariancePlayers: 50,
        ));

        self::assertNotEmpty($report->gridPoints);
        self::assertNotEmpty($report->recommendations);

        $maxInflatedCount = 0;
        foreach ($report->gridPoints as $point) {
            $maxInflatedCount = max($maxInflatedCount, $point->inflatedCount);
        }
        self::assertGreaterThan(0, $maxInflatedCount);
    }
}

