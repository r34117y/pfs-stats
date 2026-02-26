<?php

declare(strict_types=1);

namespace App\Ranking\Infrastructure;

use App\Ranking\Application\MinGamesCalibrationReport;

final class MinGamesReportWriter
{
    /**
     * @param list<string> $formats
     * @return array{dir: string, files: list<string>}
     */
    public function write(MinGamesCalibrationReport $report, string $outDir, array $formats): array
    {
        $timestamp = (new \DateTimeImmutable())->format('Ymd_His');
        $targetDir = rtrim($outDir, '/') . '/' . $timestamp;

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $files = [];
        $payload = $this->toArray($report);
        foreach ($formats as $format) {
            $normalized = strtolower(trim($format));
            if ($normalized === 'json') {
                $path = $targetDir . '/report.json';
                file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $files[] = $path;
                continue;
            }

            if ($normalized === 'md') {
                $path = $targetDir . '/report.md';
                file_put_contents($path, $this->renderMarkdown($report));
                $files[] = $path;
                continue;
            }

            if ($normalized === 'html') {
                $path = $targetDir . '/report.html';
                file_put_contents($path, $this->renderHtml($report));
                $files[] = $path;
            }
        }

        return ['dir' => $targetDir, 'files' => $files];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(MinGamesCalibrationReport $report): array
    {
        return [
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'windowCount' => $report->windowCount,
            'durationSeconds' => round($report->durationSeconds, 3),
            'config' => [
                'start' => $report->config->start->format('Y-m-d'),
                'end' => $report->config->end->format('Y-m-d'),
                'step' => $report->config->step->format('P%yY%mM%dDT%hH%iM%sS'),
                'window' => $report->config->window->format('P%yY%mM%dDT%hH%iM%sS'),
                'model' => $report->config->model,
                'eloK' => $report->config->eloK,
                'topK' => $report->config->topK,
                'nGrid' => $report->config->nGrid,
                'alphas' => $report->config->alphas,
                'nEarlyCheckpoints' => $report->config->nEarlyCheckpoints,
                'stableGames' => $report->config->stableGames,
                'minStableGames' => $report->config->minStableGames,
                'deltaRank' => $report->config->deltaRank,
                'deltaRating' => $report->config->deltaRating,
                'minGamesForPlayer' => $report->config->minGamesForPlayer,
                'seed' => $report->config->seed,
                'maxWindows' => $report->config->maxWindows,
                'persistStepGames' => $report->config->persistStepGames,
                'alphaWindow' => $report->config->alphaWindow,
            ],
            'gridPoints' => array_map(
                static fn ($p) => [
                    'nMin' => $p->nMin,
                    'falseLeaderRate' => $p->falseLeaderRate,
                    'p95WindowFalseRate' => $p->p95WindowFalseRate,
                    'windowRateMean' => $p->windowRateMean,
                    'windowRateMedian' => $p->windowRateMedian,
                    'windowRateP90' => $p->windowRateP90,
                    'coverage' => $p->coverage,
                    'excluded' => $p->excluded,
                    'persistenceP90Days' => $p->persistenceP90Days,
                    'eligibleCount' => $p->eligibleCount,
                    'falseLeaderCount' => $p->falseLeaderCount,
                ],
                $report->gridPoints
            ),
            'recommendations' => array_map(
                static fn ($r) => [
                    'alpha' => $r->alpha,
                    'alphaWindow' => $r->alphaWindow,
                    'recommendedNMin' => $r->recommendedNMin,
                ],
                $report->recommendations
            ),
            'worstWindowsByN' => $report->worstWindowsByN,
            'sensitivity' => $report->sensitivity,
            'curves' => $report->curves,
        ];
    }

    private function renderMarkdown(MinGamesCalibrationReport $report): string
    {
        $lines = [];
        $lines[] = '# PFS Min-Games Calibration Report';
        $lines[] = '';
        $lines[] = sprintf('- Generated at: %s', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $lines[] = sprintf('- Windows analyzed: %d', $report->windowCount);
        $lines[] = sprintf('- Runtime: %.2f s', $report->durationSeconds);
        $lines[] = '';
        $lines[] = '## Executive Summary';
        foreach ($report->recommendations as $recommendation) {
            $lines[] = sprintf(
                '- Alpha %.2f (window %.2f): recommended n_min=%d',
                $recommendation->alpha,
                $recommendation->alphaWindow,
                $recommendation->recommendedNMin,
            );
        }

        $lines[] = '';
        $lines[] = '## Methodology';
        $lines[] = sprintf(
            '- Sliding windows: %s -> %s, step=%s, size=%s.',
            $report->config->start->format('Y-m-d'),
            $report->config->end->format('Y-m-d'),
            $report->config->step->format('P%yY%mM%dDT%hH%iM%sS'),
            $report->config->window->format('P%yY%mM%dDT%hH%iM%sS'),
        );
        $lines[] = sprintf('- Rating model: Elo (K=%.2f), calibration-only proxy (not official PFS method).', $report->config->eloK);
        $lines[] = sprintf(
            '- False leader: in early Top-%d at n_min, outside stable Top-%d, with rank drop >= %d%s.',
            $report->config->topK,
            $report->config->topK,
            $report->config->deltaRank,
            $report->config->deltaRating > 0.0 ? sprintf(' or rating drop >= %.1f', $report->config->deltaRating) : ''
        );

        $lines[] = '';
        $lines[] = '## Results';
        $lines[] = '| n_min | false_rate % | p95_window_false_rate % | coverage % | excluded % | persistence_p90_days |';
        $lines[] = '| ---: | ---: | ---: | ---: | ---: | ---: |';
        foreach ($report->gridPoints as $point) {
            $lines[] = sprintf(
                '| %d | %.2f | %.2f | %.2f | %.2f | %s |',
                $point->nMin,
                $point->falseLeaderRate * 100.0,
                $point->p95WindowFalseRate * 100.0,
                $point->coverage * 100.0,
                $point->excluded * 100.0,
                $point->persistenceP90Days === null ? 'n/a' : (string) round($point->persistenceP90Days),
            );
        }

        $lines[] = '';
        $lines[] = '## Worst Windows';
        foreach ($report->recommendations as $recommendation) {
            $nKey = (string) $recommendation->recommendedNMin;
            $lines[] = sprintf('### n_min=%d', $recommendation->recommendedNMin);
            $lines[] = '| Window Start | Window End | false_rate % | false | eligible |';
            $lines[] = '| --- | --- | ---: | ---: | ---: |';
            foreach (($report->worstWindowsByN[$nKey] ?? []) as $row) {
                $lines[] = sprintf(
                    '| %s | %s | %.2f | %d | %d |',
                    $row['start'],
                    $row['end'],
                    ((float) $row['rate']) * 100.0,
                    (int) $row['false'],
                    (int) $row['eligible'],
                );
            }
            $lines[] = '';
        }

        $lines[] = '## Limitations and Next Steps';
        $lines[] = '- Elo is used as a calibration model and differs from the official PFS ranking algorithm.';
        $lines[] = '- Results are sensitive to Top-K, stable set definition, and temporal clustering by tournament.';
        $lines[] = '- Consider bootstrap re-sampling or Glicko-2 RD gating as follow-up checks.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function renderHtml(MinGamesCalibrationReport $report): string
    {
        $md = htmlspecialchars($this->renderMarkdown($report), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<html><head><meta charset=\"utf-8\"><title>PFS Min-Games Calibration</title></head><body><pre>{$md}</pre></body></html>";
    }
}

