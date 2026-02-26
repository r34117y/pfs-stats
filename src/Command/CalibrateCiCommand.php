<?php

declare(strict_types=1);

namespace App\Command;

use App\Ranking\Application\CalibrateCiService;
use App\Ranking\Application\CiCalibrationConfig;
use App\Ranking\Application\CiCalibrationReport;
use App\Ranking\Application\CiGridPoint;
use App\Ranking\Infrastructure\CiReportWriter;
use App\Ranking\Infrastructure\GamesRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pfs:rank:calibrate-ci',
    description: 'Calibrate uncertainty-based ranking eligibility using Bradley-Terry confidence intervals.',
)]
final class CalibrateCiCommand extends Command
{
    public function __construct(
        private readonly CalibrateCiService $calibrateCiService,
        private readonly GamesRepository $gamesRepository,
        private readonly CiReportWriter $reportWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Start date YYYY-MM-DD (default: earliest game date)')
            ->addOption('end', null, InputOption::VALUE_REQUIRED, 'End date YYYY-MM-DD (default: latest game date)')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Window size, ISO-8601 interval', 'P2Y')
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'Window step, ISO-8601 interval', 'P1M')
            ->addOption('top-k', null, InputOption::VALUE_REQUIRED, 'Top-K cutoff', '50')
            ->addOption('n-early', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Early-games checkpoints (repeatable or CSV)', ['35'])
            ->addOption('stable-games', null, InputOption::VALUE_REQUIRED, 'Stable set minimum games', '120')
            ->addOption('alpha', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target alpha; repeatable', ['0.05'])
            ->addOption('ci', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'CI levels; repeatable', ['0.95', '0.99'])
            ->addOption('sigma-prior', null, InputOption::VALUE_REQUIRED, 'Prior std-dev for skill regularization', '2.0')
            ->addOption('max-iter', null, InputOption::VALUE_REQUIRED, 'Maximum model iterations', '30')
            ->addOption('tol', null, InputOption::VALUE_REQUIRED, 'Convergence tolerance', '1e-6')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Deterministic seed', '1234')
            ->addOption('w-grid', null, InputOption::VALUE_REQUIRED, 'Candidate CI width grid CSV (default auto 0.5..3.0:0.1)')
            ->addOption('min-games-for-player', null, InputOption::VALUE_REQUIRED, 'Ignore tiny player samples', '5')
            ->addOption('out-dir', null, InputOption::VALUE_REQUIRED, 'Report output directory', 'var/reports/pfs-ci-calibration')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output formats CSV (md,json,html)', 'md,json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print planned window count')
            ->addOption('max-windows', null, InputOption::VALUE_REQUIRED, 'Limit analyzed windows (0 = all)', '0')
            ->addOption('delta-rank', null, InputOption::VALUE_REQUIRED, 'Minimum rank drop threshold for inflated events', '30')
            ->addOption('delta-skill', null, InputOption::VALUE_REQUIRED, 'Optional skill drop threshold, 0 disables', '0')
            ->addOption('max-full-covariance-players', null, InputOption::VALUE_REQUIRED, 'Max active players for full covariance inversion', '120');
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

        $nEarly = $this->parseIntList($input->getOption('n-early'));
        if ($nEarly === []) {
            $io->error('Invalid --n-early. Provide positive integers via CSV or repeated option.');

            return Command::FAILURE;
        }

        $alphas = $this->parseFloatList($input->getOption('alpha'));
        $ciLevels = $this->parseFloatList($input->getOption('ci'));
        $wGrid = $this->parseWGrid($this->stringOrNull($input->getOption('w-grid')));
        $formats = $this->parseFormats((string) $input->getOption('format'));
        $windowCount = $this->countWindows($start, $end, $step, $window, (int) $input->getOption('max-windows'));

        $config = new CiCalibrationConfig(
            start: $start,
            end: $end,
            step: $step,
            window: $window,
            topK: (int) $input->getOption('top-k'),
            nEarly: $nEarly,
            stableGames: (int) $input->getOption('stable-games'),
            alphas: $alphas,
            ciLevels: $ciLevels,
            sigmaPrior: (float) $input->getOption('sigma-prior'),
            maxIter: (int) $input->getOption('max-iter'),
            tol: (float) $input->getOption('tol'),
            wGrid: $wGrid,
            minGamesForPlayer: (int) $input->getOption('min-games-for-player'),
            seed: (int) $input->getOption('seed'),
            outDir: (string) $input->getOption('out-dir'),
            formats: $formats,
            maxWindows: (int) $input->getOption('max-windows'),
            deltaRank: (int) $input->getOption('delta-rank'),
            deltaSkill: (float) $input->getOption('delta-skill'),
            maxFullCovariancePlayers: (int) $input->getOption('max-full-covariance-players'),
        );

        $io->title('PFS CI calibration (Approach C)');
        $io->definitionList(
            ['Date range' => sprintf('%s -> %s', $start->format('Y-m-d'), $end->format('Y-m-d'))],
            ['Window/step' => sprintf('%s / %s', (string) $input->getOption('window'), (string) $input->getOption('step'))],
            ['Top-K / n_early / stable' => sprintf('%d / %s / %d', $config->topK, implode(',', $config->nEarly), $config->stableGames)],
            ['Alpha' => implode(', ', array_map(static fn (float $a): string => (string) $a, $alphas))],
            ['CI levels' => implode(', ', array_map(static fn (float $ci): string => (string) $ci, $ciLevels))],
            ['W grid points' => (string) count($wGrid)],
            ['Planned windows' => (string) $windowCount],
        );

        if ((bool) $input->getOption('dry-run')) {
            $io->success('Dry run complete.');

            return Command::SUCCESS;
        }

        $progress = new ProgressBar($output, max(1, $windowCount));
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $progress->start();

        $report = $this->calibrateCiService->calibrate(
            $config,
            static function (int $current, int $total, \App\Ranking\Domain\WindowDefinition $window) use ($progress): void {
                $progress->advance();
            }
        );

        $progress->finish();
        $io->newLine(2);

        $this->renderGridTables($io, $report);
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

    private function renderGridTables(SymfonyStyle $io, CiCalibrationReport $report): void
    {
        $byCi = [];
        foreach ($report->gridPoints as $point) {
            $key = sprintf('%.6f', $point->ciLevel);
            $byCi[$key][] = $point;
        }

        foreach ($byCi as $ciKey => $points) {
            usort($points, static fn (CiGridPoint $a, CiGridPoint $b): int => $a->wMax <=> $b->wMax);
            $rows = [];
            foreach ($points as $point) {
                $rows[] = [
                    number_format($point->wMax, 3),
                    number_format($point->inflatedProbability * 100.0, 2) . '%',
                    number_format($point->p95WindowInflatedProbability * 100.0, 2) . '%',
                    number_format($point->coverage * 100.0, 2) . '%',
                    $point->medianGamesToQualify === null ? 'n/a' : (string) round($point->medianGamesToQualify),
                    $point->p90GamesToQualify === null ? 'n/a' : (string) round($point->p90GamesToQualify),
                ];
            }

            $io->section(sprintf('Calibration grid (CI=%s%%)', number_format(((float) $ciKey) * 100.0, 0)));
            $io->table(
                ['W_max', 'inflated_prob', 'p95_window_inflated_prob', 'coverage', 'median_games_to_qualify', 'p90_games_to_qualify'],
                $rows
            );
        }
    }

    private function renderRecommendations(SymfonyStyle $io, CiCalibrationReport $report): void
    {
        $rows = [];
        foreach ($report->recommendations as $recommendation) {
            $rows[] = [
                number_format($recommendation->alpha, 2),
                number_format($recommendation->ciLevel * 100.0, 0) . '%',
                number_format($recommendation->recommendedWMax, 3),
                $recommendation->metric === null ? 'n/a' : number_format($recommendation->metric->inflatedProbability * 100.0, 2) . '%',
                $recommendation->metric === null ? 'n/a' : number_format($recommendation->metric->coverage * 100.0, 2) . '%',
            ];
        }

        $io->section('Recommended W_max');
        $io->table(['alpha', 'ci', 'recommended_w_max', 'inflated_prob', 'coverage'], $rows);
    }

    private function renderWorstWindows(SymfonyStyle $io, CiCalibrationReport $report): void
    {
        $io->section('Worst windows by recommendation');
        foreach ($report->recommendations as $recommendation) {
            $key = sprintf('alpha=%.4f|ci=%.4f|w=%.4f', $recommendation->alpha, $recommendation->ciLevel, $recommendation->recommendedWMax);
            $rows = [];
            foreach (($report->worstWindowsByRecommendation[$key] ?? []) as $row) {
                $rows[] = [
                    $row['start'],
                    $row['end'],
                    number_format(((float) $row['rate']) * 100.0, 2) . '%',
                    (int) $row['inflated'],
                    (int) $row['eligible'],
                ];
            }

            $io->writeln(sprintf(
                'alpha=%s ci=%s%% w_max=%s',
                number_format($recommendation->alpha, 2),
                number_format($recommendation->ciLevel * 100.0, 0),
                number_format($recommendation->recommendedWMax, 3),
            ));
            $io->table(['start', 'end', 'inflated_prob', 'inflated', 'eligible'], $rows);
        }
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

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function parseIntList(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];
        $result = [];
        foreach ($values as $value) {
            foreach (explode(',', (string) $value) as $part) {
                $trimmed = trim($part);
                if ($trimmed === '') {
                    continue;
                }
                $n = (int) $trimmed;
                if ($n > 0) {
                    $result[] = $n;
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
        $values = is_array($raw) ? $raw : [$raw];
        $result = [];
        foreach ($values as $value) {
            foreach (explode(',', (string) $value) as $part) {
                $trimmed = trim($part);
                if ($trimmed === '') {
                    continue;
                }
                $f = (float) $trimmed;
                if ($f > 0.0) {
                    $result[] = $f;
                }
            }
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @return list<float>
     */
    private function parseWGrid(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            $result = [];
            for ($w = 0.5; $w <= 3.000001; $w += 0.1) {
                $result[] = round($w, 3);
            }

            return $result;
        }

        $result = [];
        foreach (explode(',', $raw) as $part) {
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }
            $w = (float) $trimmed;
            if ($w > 0.0) {
                $result[] = $w;
            }
        }

        $result = array_values(array_unique($result));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function parseFormats(string $raw): array
    {
        $result = [];
        foreach (explode(',', $raw) as $part) {
            $format = strtolower(trim($part));
            if ($format === '') {
                continue;
            }
            if (in_array($format, ['md', 'json', 'html'], true)) {
                $result[] = $format;
            }
        }

        if ($result === []) {
            return ['md', 'json'];
        }

        return array_values(array_unique($result));
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

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

