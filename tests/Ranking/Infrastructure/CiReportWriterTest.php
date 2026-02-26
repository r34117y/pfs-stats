<?php

declare(strict_types=1);

namespace App\Tests\Ranking\Infrastructure;

use App\Ranking\Application\CiCalibrationConfig;
use App\Ranking\Application\CiCalibrationReport;
use App\Ranking\Application\CiGridPoint;
use App\Ranking\Application\CiRecommendation;
use App\Ranking\Infrastructure\CiReportWriter;
use PHPUnit\Framework\TestCase;

final class CiReportWriterTest extends TestCase
{
    public function testWriterOutputsRequiredFilesAndSections(): void
    {
        $writer = new CiReportWriter();
        $config = new CiCalibrationConfig(
            start: new \DateTimeImmutable('2020-01-01'),
            end: new \DateTimeImmutable('2021-12-31'),
            step: new \DateInterval('P1M'),
            window: new \DateInterval('P2Y'),
            topK: 50,
            nEarly: [35, 40],
            stableGames: 120,
            alphas: [0.05, 0.01],
            ciLevels: [0.95, 0.99],
            sigmaPrior: 2.0,
            maxIter: 30,
            tol: 1e-6,
            wGrid: [0.8, 1.0, 1.2],
            minGamesForPlayer: 5,
            seed: 1234,
            outDir: 'var/reports/pfs-ci-calibration',
            formats: ['md', 'json'],
            maxWindows: 0,
            deltaRank: 30,
            deltaSkill: 0.0,
        );

        $point = new CiGridPoint(
            ciLevel: 0.95,
            wMax: 1.2,
            inflatedProbability: 0.04,
            p95WindowInflatedProbability: 0.05,
            coverage: 0.71,
            medianGamesToQualify: 48.0,
            p90GamesToQualify: 82.0,
            eligibleCount: 100,
            inflatedCount: 4,
        );

        $recommendation = new CiRecommendation(0.05, 0.95, 1.2, $point);
        $report = new CiCalibrationReport(
            config: $config,
            windowCount: 12,
            durationSeconds: 1.3,
            gridPoints: [$point],
            recommendations: [$recommendation],
            worstWindowsByRecommendation: [
                'alpha=0.0500|ci=0.9500|w=1.2000' => [['start' => '2020-01-01', 'end' => '2021-12-31', 'rate' => 0.15, 'inflated' => 3, 'eligible' => 20]],
            ],
            sensitivity: ['35' => ['inflatedProbability' => 0.06]],
            curves: ['0.95' => ['wToInflatedProbability' => [['wMax' => 1.2, 'value' => 0.04]]]],
        );

        $baseDir = sys_get_temp_dir() . '/pfs-ci-report-tests';
        if (is_dir($baseDir)) {
            $this->removeDir($baseDir);
        }

        $result = $writer->write($report, $baseDir, ['md', 'json']);
        self::assertDirectoryExists($result['dir']);
        self::assertFileExists($result['dir'] . '/report.md');
        self::assertFileExists($result['dir'] . '/report.json');

        $json = json_decode((string) file_get_contents($result['dir'] . '/report.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('config', $json);
        self::assertArrayHasKey('gridPoints', $json);
        self::assertArrayHasKey('recommendations', $json);

        $md = (string) file_get_contents($result['dir'] . '/report.md');
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

