<?php

declare(strict_types=1);

namespace App\Command;

use App\Ranking\Application\CalibrateRdService;
use App\Ranking\Application\CalibrationConfig;
use App\Ranking\Application\CalibrationReport;
use App\Ranking\Infrastructure\GamesRepository;
use App\Ranking\Infrastructure\ReportWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pfs:rank:calibrate-rd',
    description: 'Calibrate Glicko-2 RD threshold for 2-year sliding ranking windows.',
)]
final class CalibrateRdCommand extends Command
{
    public function __construct(
        private readonly CalibrateRdService $calibrateRdService,
        private readonly GamesRepository $gamesRepository,
        private readonly ReportWriter $reportWriter,
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
            ->addOption('k', null, InputOption::VALUE_REQUIRED, 'Top-K cutoff', '50')
            ->addOption('early-games', null, InputOption::VALUE_REQUIRED, 'Early games threshold', '35')
            ->addOption('stable-games', null, InputOption::VALUE_REQUIRED, 'Stable games threshold', '120')
            ->addOption('alpha', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target alpha; repeatable', ['0.05'])
            ->addOption('tau', null, InputOption::VALUE_REQUIRED, 'Glicko-2 tau', '0.5')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Deterministic seed', '1234')
            ->addOption('out-dir', null, InputOption::VALUE_REQUIRED, 'Report output directory', 'var/reports/pfs-rd-calibration')
            ->addOption('rd-grid', null, InputOption::VALUE_REQUIRED, 'Comma-separated RD candidates')
            ->addOption('min-games-for-player', null, InputOption::VALUE_REQUIRED, 'Ignore tiny player samples', '5')
            ->addOption('min-stable-games', null, InputOption::VALUE_REQUIRED, 'Minimum games required for stable reference', '120')
            ->addOption('delta-rank', null, InputOption::VALUE_REQUIRED, 'Minimum rank drop threshold', '30')
            ->addOption('delta-rating', null, InputOption::VALUE_REQUIRED, 'Minimum rating drop threshold', '100')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output formats CSV (md,json,html)', 'md,json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print planned window count')
            ->addOption('days-per-rating-period', null, InputOption::VALUE_REQUIRED, 'Inactivity model: days per Glicko period', '1');
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

        $rdGrid = $this->parseRdGrid($this->stringOrNull($input->getOption('rd-grid')));
        $alphas = $this->parseFloatList($input->getOption('alpha'));

        $config = new CalibrationConfig(
            start: $start,
            end: $end,
            step: $step,
            window: $window,
            k: (int) $input->getOption('k'),
            earlyGames: (int) $input->getOption('early-games'),
            stableGames: (int) $input->getOption('stable-games'),
            alphas: $alphas,
            tau: (float) $input->getOption('tau'),
            seed: (int) $input->getOption('seed'),
            outDir: (string) $input->getOption('out-dir'),
            rdGrid: $rdGrid,
            minGamesForPlayer: (int) $input->getOption('min-games-for-player'),
            minStableGames: (int) $input->getOption('min-stable-games'),
            deltaRank: (int) $input->getOption('delta-rank'),
            deltaRating: (float) $input->getOption('delta-rating'),
            daysPerRatingPeriod: (float) $input->getOption('days-per-rating-period'),
        );

        $windowCount = $this->countWindows($start, $end, $step, $window);

        $io->title('PFS Glicko-2 RD calibration');
        $io->definitionList(
            ['Date range' => sprintf('%s -> %s', $start->format('Y-m-d'), $end->format('Y-m-d'))],
            ['Window/step' => sprintf('%s / %s', (string) $input->getOption('window'), (string) $input->getOption('step'))],
            ['Top-K / early / stable' => sprintf('%d / %d / %d', $config->k, $config->earlyGames, $config->stableGames)],
            ['Alpha' => implode(', ', array_map(static fn (float $a): string => (string) $a, $alphas))],
            ['RD grid size' => (string) count($rdGrid)],
            ['Planned windows' => (string) $windowCount],
        );

        if ((bool) $input->getOption('dry-run')) {
            $io->success('Dry run complete.');

            return Command::SUCCESS;
        }

        $progressBar = new ProgressBar($output, max(1, $windowCount));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $progressBar->start();

        $report = $this->calibrateRdService->calibrate(
            $config,
            static function (int $current, int $total, \App\Ranking\Domain\WindowDefinition $window) use ($progressBar): void {
                $progressBar->advance();
            }
        );

        $progressBar->finish();
        $io->newLine(2);

        $this->renderMetricsTable($io, $report);
        $this->renderRecommendationTable($io, $report);

        $formats = $this->parseFormats((string) $input->getOption('format'));
        $written = $this->reportWriter->write($report, $config->outDir, $formats);

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

    private function renderMetricsTable(SymfonyStyle $io, CalibrationReport $report): void
    {
        $rows = [];
        foreach ($report->metrics as $metric) {
            $rows[] = [
                (int) round($metric->rdMax),
                number_format($metric->inflatedProbability * 100.0, 2) . '%',
                number_format($metric->coverage * 100.0, 2) . '%',
                $metric->eligibleCount,
                $metric->inflatedCount,
                $metric->gamesToQualifyP50 === null ? 'n/a' : (string) round($metric->gamesToQualifyP50),
                $metric->gamesToQualifyP90 === null ? 'n/a' : (string) round($metric->gamesToQualifyP90),
            ];
        }

        $io->section('Candidate RD grid');
        $io->table(
            ['RD_max', 'Inflated %', 'Coverage %', 'Eligible', 'Inflated', 'Games p50', 'Games p90'],
            $rows
        );
    }

    private function renderRecommendationTable(SymfonyStyle $io, CalibrationReport $report): void
    {
        $rows = [];
        foreach ($report->recommendations as $recommendation) {
            $rows[] = [
                number_format($recommendation->alpha, 2),
                (int) round($recommendation->recommendedRdMax),
                $recommendation->metric === null ? 'n/a' : number_format($recommendation->metric->inflatedProbability * 100.0, 2) . '%',
                $recommendation->metric === null ? 'n/a' : number_format($recommendation->metric->coverage * 100.0, 2) . '%',
            ];
        }

        $io->section('Recommended RD_max');
        $io->table(['Alpha', 'Recommended RD_max', 'Inflated %', 'Coverage %'], $rows);
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
     * @return list<float>
     */
    private function parseRdGrid(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            $result = [];
            for ($rd = 350; $rd >= 60; $rd -= 10) {
                $result[] = (float) $rd;
            }

            return $result;
        }

        $parts = explode(',', $raw);
        $result = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }

            $result[] = (float) $trimmed;
        }

        if ($result === []) {
            return [350.0, 320.0, 300.0, 280.0, 260.0, 240.0, 220.0, 200.0, 180.0, 160.0, 140.0, 120.0, 100.0, 80.0, 60.0];
        }

        $result = array_values(array_unique($result));
        rsort($result, SORT_NUMERIC);

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
            if (!is_scalar($value)) {
                continue;
            }
            $result[] = (float) $value;
        }

        if ($result === []) {
            return [0.05];
        }

        return $result;
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
    ): int {
        $count = 0;

        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->add($step)) {
            $windowEnd = $cursor->add($window)->modify('-1 day');
            if ($windowEnd > $end) {
                break;
            }
            $count++;
        }

        return $count;
    }
}
