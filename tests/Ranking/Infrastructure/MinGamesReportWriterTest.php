<?php

declare(strict_types=1);

namespace App\Tests\Ranking\Infrastructure;

use App\Ranking\Application\MinGamesCalibrationConfig;
use App\Ranking\Application\MinGamesCalibrationReport;
use App\Ranking\Application\MinGamesGridPoint;
use App\Ranking\Application\MinGamesRecommendation;
use App\Ranking\Infrastructure\MinGamesReportWriter;
use PHPUnit\Framework\TestCase;

final class MinGamesReportWriterTest extends TestCase
{
    public function testWriterOutputsJsonAndMarkdownWithRequiredSections(): void
    {
        $writer = new MinGamesReportWriter();
        $config = new MinGamesCalibrationConfig(
            start: new \DateTimeImmutable('2020-01-01'),
            end: new \DateTimeImmutable('2021-12-31'),
            step: new \DateInterval('P1M'),
            window: new \DateInterval('P2Y'),
            model: 'elo',
            eloK: 20.0,
            topK: 50,
            nGrid: [30, 40],
            alphas: [0.05],
            nEarlyCheckpoints: [30, 35, 40],
            stableGames: 120,
            minStableGames: 120,
            deltaRank: 30,
            deltaRating: 0.0,
            minGamesForPlayer: 5,
            seed: 1234,
            outDir: 'var/reports/pfs-min-games',
            formats: ['md', 'json'],
        );

        $point = new MinGamesGridPoint(
            nMin: 40,
            falseLeaderRate: 0.04,
            p95WindowFalseRate: 0.05,
            windowRateMean: 0.03,
            windowRateMedian: 0.03,
            windowRateP90: 0.05,
            coverage: 0.71,
            excluded: 0.29,
            persistenceP90Days: 22.0,
            eligibleCount: 100,
            falseLeaderCount: 4,
        );

        $report = new MinGamesCalibrationReport(
            config: $config,
            windowCount: 12,
            durationSeconds: 1.1,
            gridPoints: [$point],
            recommendations: [new MinGamesRecommendation(0.05, 0.05, 40, $point)],
            worstWindowsByN: ['40' => [['start' => '2020-01-01', 'end' => '2021-12-31', 'rate' => 0.2, 'false' => 2, 'eligible' => 10]]],
            sensitivity: ['35' => ['falseLeaderRate' => 0.06]],
            curves: ['nToFalseLeaderRate' => [['nMin' => 40, 'value' => 0.04]]],
        );

        $baseDir = sys_get_temp_dir() . '/pfs-min-games-report-tests';
        if (is_dir($baseDir)) {
            $this->removeDir($baseDir);
        }

        $result = $writer->write($report, $baseDir, ['md', 'json']);
        self::assertDirectoryExists($result['dir']);

        $jsonPath = $result['dir'] . '/report.json';
        $mdPath = $result['dir'] . '/report.md';
        self::assertFileExists($jsonPath);
        self::assertFileExists($mdPath);

        $json = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('config', $json);
        self::assertArrayHasKey('gridPoints', $json);
        self::assertArrayHasKey('recommendations', $json);

        $md = (string) file_get_contents($mdPath);
        self::assertStringContainsString('## Executive Summary', $md);
        self::assertStringContainsString('## Methodology', $md);
        self::assertStringContainsString('## Results', $md);
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

