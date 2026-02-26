<?php

declare(strict_types=1);

namespace App\Command;

use App\Ranking\Application\CalibrateMinGamesService;
use App\Ranking\Application\MinGamesCalibrationConfig;
use App\Ranking\Application\MinGamesCalibrationReport;
use App\Ranking\Infrastructure\GamesRepository;
use App\Ranking\Infrastructure\MinGamesReportWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pfs:rank:calibrate-min-games',
    description: 'Calibrate ranking eligibility minimum-games threshold with 2-year sliding windows.',
)]
final class CalibrateMinGamesCommand extends Command
{
    public function __construct(
        private readonly CalibrateMinGamesService $calibrateMinGamesService,
        private readonly GamesRepository $gamesRepository,
        private readonly MinGamesReportWriter $reportWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Start date YYYY-MM-DD (default: earliest game date)')
            ->addOption('end', null, InputOption::VALUE_REQUIRED, 'End date YYYY-MM-DD (default: latest game date)')
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'Window step, ISO-8601 interval', 'P1M')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Window size, ISO-8601 interval', 'P2Y')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Rating model for calibration', 'elo')
            ->addOption('elo-k', null, InputOption::VALUE_REQUIRED, 'Elo K factor', '20')
            ->addOption('top-k', null, InputOption::VALUE_REQUIRED, 'Top-K cutoff', '50')
            ->addOption('n-grid', null, InputOption::VALUE_REQUIRED, 'Candidate min-games grid, e.g. 10..200 or 10,20,30', '10..200')
            ->addOption('alpha', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target alpha; repeatable', ['0.05'])
            ->addOption('alpha-window', null, InputOption::VALUE_REQUIRED, 'P95 window false-rate threshold; default: alpha')
            ->addOption('n-early', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sensitivity checkpoints, repeatable or CSV', ['30,35,40'])
            ->addOption('stable-games', null, InputOption::VALUE_REQUIRED, 'Stable checkpoint parameter (for reporting)', '120')
            ->addOption('min-stable-games', null, InputOption::VALUE_REQUIRED, 'Minimum games for stable reference set', '120')
            ->addOption('delta-rank', null, InputOption::VALUE_REQUIRED, 'Minimum rank drop threshold', '30')
            ->addOption('delta-rating', null, InputOption::VALUE_REQUIRED, 'Optional rating drop threshold, 0 disables', '0')
            ->addOption('min-games-for-player', null, InputOption::VALUE_REQUIRED, 'Ignore tiny player samples', '5')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Deterministic seed', '1234')
            ->addOption('out-dir', null, InputOption::VALUE_REQUIRED, 'Report output directory', 'var/reports/pfs-min-games')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output formats CSV (md,json,html)', 'md,json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print planned window count')
            ->addOption('max-windows', null, InputOption::VALUE_REQUIRED, 'Limit analyzed windows (0 = all)', '0')
            ->addOption('persist-step-games', null, InputOption::VALUE_REQUIRED, 'Games step for persistence estimation (0 disables)', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $bounds = $this->gamesRepository->findDateBounds();
        if ($bounds === null) {
            $io->warning('No games found in PFSTOURHH/PFSTOURS.');

            return Command::SUCCESS;
        }

        $start = $this->parseDate($this->stringOrNull($input->getOption('start'))) ?? $bounds['start'];
        $end = $this->parseDate($this->stringOrNull($input->getOption('end'))) ?? $bounds['end'];
        if ($start > $end) {
            $io->error('Invalid date range: start is after end.');

            return Command::FAILURE;
        }

        $step = $this->parseInterval((string) $input->getOption('step'));
        $window = $this->parseInterval((string) $input->getOption('window'));
        if ($step === null || $window === null) {
            $io->error('Invalid interval syntax for --step or --window. Use ISO-8601 durations, e.g. P1M, P2Y.');

            return Command::FAILURE;
        }

        $model = strtolower((string) $input->getOption('model'));
        if ($model !== 'elo') {
            $io->error('Only --model=elo is currently supported.');

            return Command::FAILURE;
        }

        $nGrid = $this->parseNGrid((string) $input->getOption('n-grid'));
        if ($nGrid === []) {
            $io->error('Invalid --n-grid. Use range syntax (10..200 or 10..200:5) or CSV.');

            return Command::FAILURE;
        }

        $alphas = $this->parseFloatList($input->getOption('alpha'));
        $nEarly = $this->parseIntList($input->getOption('n-early'));
        $windowCount = $this->countWindows($start, $end, $step, $window, (int) $input->getOption('max-windows'));

        $config = new MinGamesCalibrationConfig(
            start: $start,
            end: $end,
            step: $step,
            window: $window,
            model: $model,
            eloK: (float) $input->getOption('elo-k'),
            topK: (int) $input->getOption('top-k'),
            nGrid: $nGrid,
            alphas: $alphas,
            nEarlyCheckpoints: $nEarly,
            stableGames: (int) $input->getOption('stable-games'),
            minStableGames: (int) $input->getOption('min-stable-games'),
            deltaRank: (int) $input->getOption('delta-rank'),
            deltaRating: (float) $input->getOption('delta-rating'),
            minGamesForPlayer: (int) $input->getOption('min-games-for-player'),
            seed: (int) $input->getOption('seed'),
            outDir: (string) $input->getOption('out-dir'),
            formats: $this->parseFormats((string) $input->getOption('format')),
            maxWindows: (int) $input->getOption('max-windows'),
            persistStepGames: (int) $input->getOption('persist-step-games'),
            alphaWindow: $this->parseNullableFloat($input->getOption('alpha-window')),
        );

        $io->title('PFS minimum-games calibration (Approach B)');
        $io->definitionList(
            ['Date range' => sprintf('%s -> %s', $start->format('Y-m-d'), $end->format('Y-m-d'))],
            ['Window/step' => sprintf('%s / %s', (string) $input->getOption('window'), (string) $input->getOption('step'))],
            ['Model' => sprintf('%s (K=%.2f)', $model, $config->eloK)],
            ['Top-K / stable' => sprintf('%d / %d', $config->topK, $config->minStableGames)],
            ['N grid' => sprintf('%d..%d (%d points)', min($nGrid), max($nGrid), count($nGrid))],
            ['Alpha' => implode(', ', array_map(static fn (float $a): string => (string) $a, $alphas))],
            ['Planned windows' => (string) $windowCount],
        );

        if ((bool) $input->getOption('dry-run')) {
            $io->success('Dry run complete.');

            return Command::SUCCESS;
        }

        $progress = new ProgressBar($output, max(1, $windowCount));
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $progress->start();

        $report = $this->calibrateMinGamesService->calibrate(
            $config,
            static function (int $current, int $total, \App\Ranking\Domain\WindowDefinition $window) use ($progress): void {
                $progress->advance();
            }
        );

        $progress->finish();
        $io->newLine(2);

        $this->renderMainTable($io, $report);
        $this->renderRecommendations($io, $report);
        $this->renderWorstWindows($io, $report);

        $written = $this->reportWriter->write($report, $config->outDir, $config->formats);
        $io->success(sprintf(
            'Completed: windows=%d runtime=%.2fs reportDir=%s',
            $report->windowCount,
            $report->durationSeconds,
            $written['dir']
        ));
        foreach ($written['files'] as $file) {
            $io->writeln(' - ' . $file);
        }

        return Command::SUCCESS;
    }

    private function renderMainTable(SymfonyStyle $io, MinGamesCalibrationReport $report): void
    {
        $rows = [];
        foreach ($report->gridPoints as $point) {
            $rows[] = [
                $point->nMin,
                number_format($point->falseLeaderRate * 100.0, 2) . '%',
                number_format($point->p95WindowFalseRate * 100.0, 2) . '%',
                number_format($point->coverage * 100.0, 2) . '%',
                number_format($point->excluded * 100.0, 2) . '%',
                $point->persistenceP90Days === null ? 'n/a' : (string) round($point->persistenceP90Days),
            ];
        }

        $io->section('Calibration grid');
        $io->table(
            ['n_min', 'false_rate', 'p95_window_false_rate', 'coverage', 'excluded', 'persistence_p90_days'],
            $rows
        );
    }

    private function renderRecommendations(SymfonyStyle $io, MinGamesCalibrationReport $report): void
    {
        $rows = [];
        foreach ($report->recommendations as $recommendation) {
            $rows[] = [
                number_format($recommendation->alpha, 2),
                number_format($recommendation->alphaWindow, 2),
                $recommendation->recommendedNMin,
                $recommendation->metric === null ? 'n/a' : number_format($recommendation->metric->falseLeaderRate * 100.0, 2) . '%',
                $recommendation->metric === null ? 'n/a' : number_format($recommendation->metric->coverage * 100.0, 2) . '%',
            ];
        }

        $io->section('Recommended n_min');
        $io->table(['alpha', 'alpha_window', 'recommended_n_min', 'false_rate', 'coverage'], $rows);
    }

    private function renderWorstWindows(SymfonyStyle $io, MinGamesCalibrationReport $report): void
    {
        $io->section('Top 5 worst windows');
        foreach ($report->recommendations as $recommendation) {
            $rows = [];
            foreach (($report->worstWindowsByN[(string) $recommendation->recommendedNMin] ?? []) as $row) {
                $rows[] = [
                    $row['start'],
                    $row['end'],
                    number_format(((float) $row['rate']) * 100.0, 2) . '%',
                    (int) $row['false'],
                    (int) $row['eligible'],
                ];
            }

            $io->writeln(sprintf('alpha=%s n_min=%d', number_format($recommendation->alpha, 2), $recommendation->recommendedNMin));
            $io->table(['start', 'end', 'false_rate', 'false', 'eligible'], $rows);
        }
    }

    /**
     * @return list<int>
     */
    private function parseNGrid(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        if (preg_match('/^(\d+)\.\.(\d+)(?::(\d+))?$/', $trimmed, $m) === 1) {
            $start = (int) $m[1];
            $end = (int) $m[2];
            $step = isset($m[3]) && $m[3] !== '' ? max(1, (int) $m[3]) : 1;

            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            $result = [];
            for ($n = $start; $n <= $end; $n += $step) {
                $result[] = $n;
            }

            return $result;
        }

        return $this->parseIntList($trimmed);
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function parseIntList(mixed $raw): array
    {
        $chunks = is_array($raw) ? $raw : [$raw];
        $result = [];

        foreach ($chunks as $chunk) {
            if (!is_scalar($chunk)) {
                continue;
            }
            foreach (explode(',', (string) $chunk) as $item) {
                $value = trim($item);
                if ($value === '' || !is_numeric($value)) {
                    continue;
                }
                $int = (int) $value;
                if ($int > 0) {
                    $result[] = $int;
                }
            }
        }

        $result = array_values(array_unique($result));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @param mixed $raw
     * @return list<float>
     */
    private function parseFloatList(mixed $raw): array
    {
        $chunks = is_array($raw) ? $raw : [$raw];
        $result = [];
        foreach ($chunks as $chunk) {
            if (!is_scalar($chunk)) {
                continue;
            }
            foreach (explode(',', (string) $chunk) as $item) {
                $value = trim($item);
                if ($value === '' || !is_numeric($value)) {
                    continue;
                }
                $result[] = (float) $value;
            }
        }

        return $result === [] ? [0.05] : array_values(array_unique($result));
    }

    /**
     * @return list<string>
     */
    private function parseFormats(string $raw): array
    {
        $formats = [];
        foreach (explode(',', $raw) as $part) {
            $format = strtolower(trim($part));
            if ($format === '') {
                continue;
            }
            if (in_array($format, ['md', 'json', 'html'], true)) {
                $formats[] = $format;
            }
        }

        return $formats === [] ? ['md', 'json'] : array_values(array_unique($formats));
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date === false ? null : $date;
    }

    private function parseInterval(string $value): ?\DateInterval
    {
        try {
            return new \DateInterval($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseNullableFloat(mixed $value): ?float
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        return (float) $trimmed;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function countWindows(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        \DateInterval $step,
        \DateInterval $window,
        int $maxWindows,
    ): int {
        $count = 0;
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->add($step)) {
            $windowEnd = $cursor->add($window)->modify('-1 day');
            if ($windowEnd > $end) {
                break;
            }
            $count++;
            if ($maxWindows > 0 && $count >= $maxWindows) {
                break;
            }
        }

        return $count;
    }
}
