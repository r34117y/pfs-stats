<?php

declare(strict_types=1);

namespace App\Ranking\Infrastructure;

use App\Ranking\Application\CiCalibrationReport;
use App\Ranking\Application\CiGridPoint;

final class CiReportWriter
{
    /**
     * @param list<string> $formats
     * @return array{dir: string, files: list<string>}
     */
    public function write(CiCalibrationReport $report, string $outDir, array $formats): array
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
    private function toArray(CiCalibrationReport $report): array
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
                'topK' => $report->config->topK,
                'nEarly' => $report->config->nEarly,
                'stableGames' => $report->config->stableGames,
                'alphas' => $report->config->alphas,
                'ciLevels' => $report->config->ciLevels,
                'sigmaPrior' => $report->config->sigmaPrior,
                'maxIter' => $report->config->maxIter,
                'tol' => $report->config->tol,
                'wGrid' => $report->config->wGrid,
                'minGamesForPlayer' => $report->config->minGamesForPlayer,
                'seed' => $report->config->seed,
                'deltaRank' => $report->config->deltaRank,
                'deltaSkill' => $report->config->deltaSkill,
                'maxWindows' => $report->config->maxWindows,
            ],
            'gridPoints' => array_map(
                static fn (CiGridPoint $p): array => [
                    'ciLevel' => $p->ciLevel,
                    'wMax' => $p->wMax,
                    'inflatedProbability' => $p->inflatedProbability,
                    'p95WindowInflatedProbability' => $p->p95WindowInflatedProbability,
                    'coverage' => $p->coverage,
                    'medianGamesToQualify' => $p->medianGamesToQualify,
                    'p90GamesToQualify' => $p->p90GamesToQualify,
                    'eligibleCount' => $p->eligibleCount,
                    'inflatedCount' => $p->inflatedCount,
                ],
                $report->gridPoints
            ),
            'recommendations' => array_map(
                static fn ($r): array => [
                    'alpha' => $r->alpha,
                    'ciLevel' => $r->ciLevel,
                    'recommendedWMax' => $r->recommendedWMax,
                ],
                $report->recommendations
            ),
            'worstWindowsByRecommendation' => $report->worstWindowsByRecommendation,
            'sensitivity' => $report->sensitivity,
            'curves' => $report->curves,
        ];
    }

    private function renderMarkdown(CiCalibrationReport $report): string
    {
        $lines = [];
        $lines[] = '# PFS CI Calibration Report';
        $lines[] = '';
        $lines[] = sprintf('- Generated at: %s', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $lines[] = sprintf('- Windows analyzed: %d', $report->windowCount);
        $lines[] = sprintf('- Runtime: %.2f s', $report->durationSeconds);
        $lines[] = '';
        $lines[] = '## Executive Summary';
        foreach ($report->recommendations as $recommendation) {
            $lines[] = sprintf(
                '- CI %.0f%%, alpha %.2f: W_max=%.3f, inflated=%.2f%%, coverage=%.2f%%, implied n p50=%s, p90=%s',
                $recommendation->ciLevel * 100.0,
                $recommendation->alpha,
                $recommendation->recommendedWMax,
                ($recommendation->metric?->inflatedProbability ?? 0.0) * 100.0,
                ($recommendation->metric?->coverage ?? 0.0) * 100.0,
                $recommendation->metric?->medianGamesToQualify === null ? 'n/a' : (string) round($recommendation->metric->medianGamesToQualify),
                $recommendation->metric?->p90GamesToQualify === null ? 'n/a' : (string) round($recommendation->metric->p90GamesToQualify),
            );
        }

        $lines[] = '';
        $lines[] = '## Methodology';
        $lines[] = '- Bradley-Terry logistic model with Gaussian prior (L2 regularization) for calibration-only eligibility analysis.';
        $lines[] = '- This tool calibrates an uncertainty-based eligibility criterion and does not reproduce official PFS ranking points.';
        $lines[] = sprintf(
            '- Sliding windows: %s -> %s, step=%s, size=%s.',
            $report->config->start->format('Y-m-d'),
            $report->config->end->format('Y-m-d'),
            $report->config->step->format('P%yY%mM%dDT%hH%iM%sS'),
            $report->config->window->format('P%yY%mM%dDT%hH%iM%sS'),
        );
        $lines[] = sprintf(
            '- Inflated-top criterion: early Top-%d at n_early=%d vs stable set at >=%d games, deltaRank >= %d%s.',
            $report->config->topK,
            $report->config->nEarly[0] ?? 35,
            $report->config->stableGames,
            $report->config->deltaRank,
            $report->config->deltaSkill > 0.0 ? sprintf(' or deltaSkill >= %.3f', $report->config->deltaSkill) : ''
        );

        $lines[] = '';
        $lines[] = '## Results';
        $lines[] = '| CI | W_max | inflated_prob % | p95_window_inflated_prob % | coverage % | median_games_to_qualify | p90_games_to_qualify |';
        $lines[] = '| ---: | ---: | ---: | ---: | ---: | ---: | ---: |';
        foreach ($report->gridPoints as $point) {
            $lines[] = sprintf(
                '| %.0f%% | %.3f | %.2f | %.2f | %.2f | %s | %s |',
                $point->ciLevel * 100.0,
                $point->wMax,
                $point->inflatedProbability * 100.0,
                $point->p95WindowInflatedProbability * 100.0,
                $point->coverage * 100.0,
                $point->medianGamesToQualify === null ? 'n/a' : (string) round($point->medianGamesToQualify),
                $point->p90GamesToQualify === null ? 'n/a' : (string) round($point->p90GamesToQualify),
            );
        }

        $lines[] = '';
        $lines[] = '## Limitations and Next Steps';
        $lines[] = '- Draws are approximated as 0.5 outcome in Bernoulli likelihood.';
        $lines[] = '- Diagonal covariance approximation is used when active-player count is high.';
        $lines[] = '- Consider Davidson draw extension or time-varying skill models for future iterations.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function renderHtml(CiCalibrationReport $report): string
    {
        $md = htmlspecialchars($this->renderMarkdown($report), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<html><head><meta charset=\"utf-8\"><title>PFS CI Calibration</title></head><body><pre>{$md}</pre></body></html>";
    }
}

