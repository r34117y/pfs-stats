<?php

declare(strict_types=1);

namespace App\Tests\Ranking\Infrastructure;

use App\Ranking\Application\CalibrationConfig;
use App\Ranking\Application\CalibrationReport;
use App\Ranking\Application\CandidateMetric;
use App\Ranking\Application\Recommendation;
use App\Ranking\Infrastructure\ReportWriter;
use PHPUnit\Framework\TestCase;

final class ReportWriterTest extends TestCase
{
    public function testWriterOutputsExpectedFilesAndKeys(): void
    {
        $writer = new ReportWriter();

        $config = new CalibrationConfig(
            start: new \DateTimeImmutable('2020-01-01'),
            end: new \DateTimeImmutable('2021-12-31'),
            step: new \DateInterval('P1M'),
            window: new \DateInterval('P2Y'),
            k: 50,
            earlyGames: 35,
            stableGames: 120,
            alphas: [0.05],
            tau: 0.5,
            seed: 1234,
            outDir: 'var/reports/pfs-rd-calibration',
            rdGrid: [350.0, 300.0],
            minGamesForPlayer: 5,
            minStableGames: 120,
            deltaRank: 30,
            deltaRating: 100.0,
        );

        $metric = new CandidateMetric(300.0, 100, 3, 0.03, 0.65, 42.0, 88.0);

        $report = new CalibrationReport(
            config: $config,
            windowCount: 10,
            durationSeconds: 1.2,
            metrics: [$metric],
            recommendations: [new Recommendation(0.05, 300.0, $metric)],
            windowSummaries: [['start' => '2020-01-01', 'end' => '2021-12-31', 'games' => 10, 'playersSeen' => 2, 'playersStable' => 2]],
            sensitivity: ['35' => [['rdMax' => 300.0, 'inflatedProbability' => 0.03]]],
            curves: ['rdToInflatedProbability' => [['rdMax' => 300.0, 'value' => 0.03]]],
        );

        $baseDir = sys_get_temp_dir() . '/pfs-rd-calibration-tests';
        if (is_dir($baseDir)) {
            $this->removeDir($baseDir);
        }

        $result = $writer->write($report, $baseDir, ['md', 'json']);
        self::assertDirectoryExists($result['dir']);

        $jsonFile = $result['dir'] . '/report.json';
        $mdFile = $result['dir'] . '/report.md';

        self::assertFileExists($jsonFile);
        self::assertFileExists($mdFile);

        $json = json_decode((string) file_get_contents($jsonFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('config', $json);
        self::assertArrayHasKey('metrics', $json);
        self::assertArrayHasKey('recommendations', $json);

        $md = (string) file_get_contents($mdFile);
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
