<?php

declare(strict_types=1);

namespace App\Ranking\Infrastructure;

use App\Ranking\Application\CalibrationReport;

final class ReportWriter
{
    /**
     * @param list<string> $formats
     * @return array{dir: string, files: list<string>}
     */
    public function write(CalibrationReport $report, string $outDir, array $formats): array
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
    private function toArray(CalibrationReport $report): array
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
                'k' => $report->config->k,
                'earlyGames' => $report->config->earlyGames,
                'stableGames' => $report->config->stableGames,
                'alphas' => $report->config->alphas,
                'tau' => $report->config->tau,
                'seed' => $report->config->seed,
                'minGamesForPlayer' => $report->config->minGamesForPlayer,
                'minStableGames' => $report->config->minStableGames,
                'deltaRank' => $report->config->deltaRank,
                'deltaRating' => $report->config->deltaRating,
                'daysPerRatingPeriod' => $report->config->daysPerRatingPeriod,
            ],
            'metrics' => array_map(
                static fn ($m) => [
                    'rdMax' => $m->rdMax,
                    'eligibleCount' => $m->eligibleCount,
                    'inflatedCount' => $m->inflatedCount,
                    'inflatedProbability' => $m->inflatedProbability,
                    'coverage' => $m->coverage,
                    'gamesToQualifyP50' => $m->gamesToQualifyP50,
                    'gamesToQualifyP90' => $m->gamesToQualifyP90,
                ],
                $report->metrics
            ),
            'recommendations' => array_map(
                static fn ($r) => [
                    'alpha' => $r->alpha,
                    'recommendedRdMax' => $r->recommendedRdMax,
                    'metric' => $r->metric === null ? null : [
                        'inflatedProbability' => $r->metric->inflatedProbability,
                        'coverage' => $r->metric->coverage,
                        'gamesToQualifyP50' => $r->metric->gamesToQualifyP50,
                        'gamesToQualifyP90' => $r->metric->gamesToQualifyP90,
                    ],
                ],
                $report->recommendations
            ),
            'curves' => $report->curves,
            'windowSummaries' => $report->windowSummaries,
            'sensitivity' => $report->sensitivity,
        ];
    }

    private function renderMarkdown(CalibrationReport $report): string
    {
        $lines = [];
        $lines[] = '# PFS RD Calibration Report';
        $lines[] = '';
        $lines[] = sprintf('- Generated at: %s', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $lines[] = sprintf('- Windows analyzed: %d', $report->windowCount);
        $lines[] = sprintf('- Runtime: %.2f s', $report->durationSeconds);
        $lines[] = '';
        $lines[] = '## Executive Summary';

        foreach ($report->recommendations as $recommendation) {
            $lines[] = sprintf(
                '- Alpha %.2f: RD_max=%d, inflated=%.2f%%, coverage=%.2f%%, games p50=%s, p90=%s',
                $recommendation->alpha,
                (int) round($recommendation->recommendedRdMax),
                ($recommendation->metric?->inflatedProbability ?? 0.0) * 100.0,
                ($recommendation->metric?->coverage ?? 0.0) * 100.0,
                $recommendation->metric?->gamesToQualifyP50 === null ? 'n/a' : (string) round($recommendation->metric->gamesToQualifyP50),
                $recommendation->metric?->gamesToQualifyP90 === null ? 'n/a' : (string) round($recommendation->metric->gamesToQualifyP90),
            );
        }

        $lines[] = '';
        $lines[] = '## Methodology';
        $lines[] = '- Glicko-2 with per-game rating periods and inactivity RD inflation by elapsed days.';
        $lines[] = sprintf('- Windowing: %s to %s, step=%s, window=%s.',
            $report->config->start->format('Y-m-d'),
            $report->config->end->format('Y-m-d'),
            $report->config->step->format('P%yY%mM%dDT%hH%iM%sS'),
            $report->config->window->format('P%yY%mM%dDT%hH%iM%sS')
        );
        $lines[] = sprintf('- Inflated-top definition: Top-%d after %d games vs stable at %d games; deltaRank >= %d or deltaRating >= %.1f.',
            $report->config->k,
            $report->config->earlyGames,
            $report->config->stableGames,
            $report->config->deltaRank,
            $report->config->deltaRating,
        );

        $lines[] = '';
        $lines[] = '## Results';
        $lines[] = '| RD_max | Inflated % | Coverage % | Eligible | Inflated | Games p50 | Games p90 |';
        $lines[] = '| ---: | ---: | ---: | ---: | ---: | ---: | ---: |';

        foreach ($report->metrics as $metric) {
            $lines[] = sprintf(
                '| %d | %.2f | %.2f | %d | %d | %s | %s |',
                (int) round($metric->rdMax),
                $metric->inflatedProbability * 100.0,
                $metric->coverage * 100.0,
                $metric->eligibleCount,
                $metric->inflatedCount,
                $metric->gamesToQualifyP50 === null ? 'n/a' : (string) round($metric->gamesToQualifyP50),
                $metric->gamesToQualifyP90 === null ? 'n/a' : (string) round($metric->gamesToQualifyP90),
            );
        }

        $lines[] = '';
        $lines[] = '## Limitations and Next Steps';
        $lines[] = '- Per-game rating periods approximate tournament-batch updates.';
        $lines[] = '- Inactivity model uses elapsed-day inflation and should be stress-tested with alternate day-period factors.';
        $lines[] = '- Extend sensitivity analysis to more Top-K variants if needed.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function renderHtml(CalibrationReport $report): string
    {
        $md = htmlspecialchars($this->renderMarkdown($report), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<html><head><meta charset=\"utf-8\"><title>PFS RD Calibration</title></head><body><pre>{$md}</pre></body></html>";
    }
}
