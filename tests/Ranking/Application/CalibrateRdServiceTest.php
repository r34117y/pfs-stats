<?php

declare(strict_types=1);

namespace App\Tests\Ranking\Application;

use App\Ranking\Application\CalibrateRdService;
use App\Ranking\Application\CalibrationConfig;
use App\Ranking\Domain\WindowDefinition;
use App\Ranking\Infrastructure\GameRecord;
use App\Ranking\Infrastructure\GamesDataSource;
use PHPUnit\Framework\TestCase;

final class CalibrateRdServiceTest extends TestCase
{
    public function testInflatedTopEventDetectionOnSyntheticData(): void
    {
        $start = new \DateTimeImmutable('2020-01-01');
        $end = new \DateTimeImmutable('2021-12-31');

        $games = [
            new GameRecord(1, new \DateTimeImmutable('2020-01-10'), 1, 1, 2, 400, 300),
            new GameRecord(1, new \DateTimeImmutable('2020-01-11'), 2, 1, 2, 420, 250),
            new GameRecord(1, new \DateTimeImmutable('2020-01-12'), 3, 1, 2, 280, 410),
            new GameRecord(1, new \DateTimeImmutable('2020-01-13'), 4, 1, 2, 250, 420),
            new GameRecord(1, new \DateTimeImmutable('2020-01-14'), 5, 1, 2, 260, 430),
            new GameRecord(1, new \DateTimeImmutable('2020-01-15'), 6, 1, 2, 270, 440),
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

        $service = new CalibrateRdService($dataSource);

        $report = $service->calibrate(new CalibrationConfig(
            start: $start,
            end: $end,
            step: new \DateInterval('P1Y'),
            window: new \DateInterval('P2Y'),
            k: 1,
            earlyGames: 2,
            stableGames: 6,
            alphas: [0.05],
            tau: 0.5,
            seed: 123,
            outDir: 'var/reports/pfs-rd-calibration',
            rdGrid: [350.0],
            minGamesForPlayer: 1,
            minStableGames: 6,
            deltaRank: 1,
            deltaRating: 1.0,
            daysPerRatingPeriod: 1.0,
        ));

        self::assertCount(1, $report->metrics);
        self::assertSame(1, $report->metrics[0]->eligibleCount);
        self::assertSame(1, $report->metrics[0]->inflatedCount);
        self::assertEqualsWithDelta(1.0, $report->metrics[0]->inflatedProbability, 0.0001);
    }
}
