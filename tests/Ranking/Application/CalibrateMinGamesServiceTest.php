<?php

declare(strict_types=1);

namespace App\Tests\Ranking\Application;

use App\Ranking\Application\CalibrateMinGamesService;
use App\Ranking\Application\MinGamesCalibrationConfig;
use App\Ranking\Domain\WindowDefinition;
use App\Ranking\Infrastructure\GameRecord;
use App\Ranking\Infrastructure\GamesDataSource;
use PHPUnit\Framework\TestCase;

final class CalibrateMinGamesServiceTest extends TestCase
{
    public function testDetectsFalseLeaderOnSyntheticData(): void
    {
        $start = new \DateTimeImmutable('2020-01-01');
        $end = new \DateTimeImmutable('2021-12-31');

        $games = [
            new GameRecord(1, new \DateTimeImmutable('2020-01-10'), 1, 1, 2, 420, 300),
            new GameRecord(1, new \DateTimeImmutable('2020-01-11'), 2, 1, 2, 410, 320),
            new GameRecord(1, new \DateTimeImmutable('2020-01-12'), 3, 1, 3, 280, 430),
            new GameRecord(1, new \DateTimeImmutable('2020-01-13'), 4, 1, 3, 260, 450),
            new GameRecord(1, new \DateTimeImmutable('2020-01-14'), 5, 1, 3, 270, 440),
            new GameRecord(1, new \DateTimeImmutable('2020-01-15'), 6, 1, 3, 275, 435),
            new GameRecord(1, new \DateTimeImmutable('2020-01-16'), 7, 1, 3, 290, 420),
            new GameRecord(1, new \DateTimeImmutable('2020-01-17'), 8, 1, 3, 300, 410),
            new GameRecord(1, new \DateTimeImmutable('2020-01-18'), 9, 2, 3, 260, 440),
            new GameRecord(1, new \DateTimeImmutable('2020-01-19'), 10, 2, 3, 250, 450),
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

        $service = new CalibrateMinGamesService($dataSource);

        $report = $service->calibrate(new MinGamesCalibrationConfig(
            start: $start,
            end: $end,
            step: new \DateInterval('P1Y'),
            window: new \DateInterval('P2Y'),
            model: 'elo',
            eloK: 60.0,
            topK: 1,
            nGrid: [2],
            alphas: [0.05],
            nEarlyCheckpoints: [2],
            stableGames: 6,
            minStableGames: 6,
            deltaRank: 1,
            deltaRating: 0.0,
            minGamesForPlayer: 1,
            seed: 1234,
            outDir: 'var/reports/pfs-min-games',
            formats: ['json'],
            maxWindows: 0,
            persistStepGames: 1,
        ));

        self::assertCount(1, $report->gridPoints);
        self::assertGreaterThan(0, $report->gridPoints[0]->eligibleCount);
        self::assertGreaterThan(0, $report->gridPoints[0]->falseLeaderCount);
        self::assertGreaterThan(0.0, $report->gridPoints[0]->falseLeaderRate);
    }

    public function testBuildsWindowsInChronologicalOrderWithLimit(): void
    {
        $start = new \DateTimeImmutable('2020-01-01');
        $end = new \DateTimeImmutable('2024-12-31');
        $games = [
            new GameRecord(1, new \DateTimeImmutable('2020-06-01'), 1, 1, 2, 400, 300),
            new GameRecord(2, new \DateTimeImmutable('2021-06-01'), 1, 1, 2, 390, 310),
            new GameRecord(3, new \DateTimeImmutable('2022-06-01'), 1, 1, 2, 380, 320),
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

        $service = new CalibrateMinGamesService($dataSource);
        $observedWindows = [];

        $report = $service->calibrate(
            new MinGamesCalibrationConfig(
                start: $start,
                end: $end,
                step: new \DateInterval('P1Y'),
                window: new \DateInterval('P2Y'),
                model: 'elo',
                eloK: 20.0,
                topK: 1,
                nGrid: [2],
                alphas: [0.05],
                nEarlyCheckpoints: [],
                stableGames: 2,
                minStableGames: 2,
                deltaRank: 1,
                deltaRating: 0.0,
                minGamesForPlayer: 1,
                seed: 1,
                outDir: 'var/reports/pfs-min-games',
                formats: ['json'],
                maxWindows: 2,
                persistStepGames: 0,
            ),
            static function (int $current, int $total, WindowDefinition $window) use (&$observedWindows): void {
                $observedWindows[] = [$current, $total, $window->start->format('Y-m-d'), $window->end->format('Y-m-d')];
            }
        );

        self::assertSame(2, $report->windowCount);
        self::assertCount(2, $observedWindows);
        self::assertSame('2020-01-01', $observedWindows[0][2]);
        self::assertSame('2021-01-01', $observedWindows[1][2]);
    }
}

