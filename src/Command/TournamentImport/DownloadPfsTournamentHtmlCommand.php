<?php

declare(strict_types=1);

namespace App\Command\TournamentImport;

use App\PfsTournamentImport\CalendarTournament;
use App\Service\PfsTournamentCalendarParser;
use App\Service\PfsTournamentWebsiteClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:pfs:tournaments:download-html',
    description: 'Downloads PFS tournament pages into the local tournament_html directory.',
)]
final class DownloadPfsTournamentHtmlCommand extends Command
{
    private const int DEFAULT_FROM_YEAR = 1997;

    public function __construct(
        private readonly PfsTournamentWebsiteClient $websiteClient,
        private readonly PfsTournamentCalendarParser $calendarParser,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url-id', null, InputOption::VALUE_REQUIRED, 'Specific PFS tournament URL id to download.')
            ->addOption('from-year', null, InputOption::VALUE_REQUIRED, 'First PFS calendar year to inspect.', self::DEFAULT_FROM_YEAR)
            ->addOption('to-year', null, InputOption::VALUE_REQUIRED, 'Last PFS calendar year to inspect.', date('Y'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $urlId = $this->parseOptionalPositiveInt($input->getOption('url-id'), 'url-id', $io);
        $fromYear = $this->parseRequiredPositiveInt($input->getOption('from-year'), 'from-year', $io);
        $toYear = $this->parseRequiredPositiveInt($input->getOption('to-year'), 'to-year', $io);
        if ($urlId === false || $fromYear === false || $toYear === false) {
            return Command::INVALID;
        }

        if ($fromYear > $toYear) {
            $io->error('Option --from-year must not be greater than --to-year.');

            return Command::INVALID;
        }

        try {
            $tournaments = $this->fetchCalendarTournaments($fromYear, $toYear, $urlId);
            if ($tournaments === []) {
                $message = $urlId === null
                    ? sprintf('No PFS tournaments were found between %d and %d.', $fromYear, $toYear)
                    : sprintf('Tournament with PFS id %d was not found between %d and %d.', $urlId, $fromYear, $toYear);
                throw new \RuntimeException($message);
            }

            $outputDir = sprintf('%s/tournament_html', rtrim($this->projectDir, '/'));
            $this->ensureDirectoryExists($outputDir);

            $downloadedRows = $this->downloadTournaments($tournaments, $outputDir);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Could not download PFS tournament HTML: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['Years inspected', sprintf('%d-%d', $fromYear, $toYear)],
                ['Tournament HTML files saved', (string) count($downloadedRows)],
                ['Output directory', $outputDir],
            ],
        );
        $io->success('PFS tournament HTML download completed.');

        return Command::SUCCESS;
    }

    /**
     * @return list<CalendarTournament>
     */
    private function fetchCalendarTournaments(int $fromYear, int $toYear, ?int $urlId): array
    {
        $tournaments = [];

        for ($year = $fromYear; $year <= $toYear; $year++) {
            $calendarHtml = $this->websiteClient->fetchCalendarHtml($year);
            foreach ($this->calendarParser->parse($calendarHtml, $year) as $tournament) {
                if ($urlId !== null && $tournament->urlId !== $urlId) {
                    continue;
                }

                $tournaments[] = $tournament;
                if ($urlId !== null) {
                    break 2;
                }
            }
        }

        usort(
            $tournaments,
            static fn (CalendarTournament $a, CalendarTournament $b): int => [
                $a->startDate->format('Y-m-d'),
                $a->urlId,
            ] <=> [
                $b->startDate->format('Y-m-d'),
                $b->urlId,
            ],
        );

        return $tournaments;
    }

    /**
     * @param list<CalendarTournament> $tournaments
     * @return list<array{pfsId:int,date:string,name:string,city:string,file:string}>
     */
    private function downloadTournaments(array $tournaments, string $outputDir): array
    {
        $rows = [];
        $usedFilenames = [];

        foreach ($tournaments as $tournament) {
            $html = $this->websiteClient->fetchTournamentHtml($tournament->urlId);
            $filename = $this->buildUniqueFilename($tournament, $usedFilenames);
            $path = sprintf('%s/%s', $outputDir, $filename);

            if (file_put_contents($path, $html) === false) {
                throw new \RuntimeException(sprintf('Could not write file: %s', $path));
            }

            $rows[] = [
                'pfsId' => $tournament->urlId,
                'date' => $tournament->startDate->format('Y-m-d'),
                'name' => $tournament->name,
                'city' => $tournament->location,
                'file' => $filename,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, true> $usedFilenames
     */
    private function buildUniqueFilename(CalendarTournament $tournament, array &$usedFilenames): string
    {
        $base = sprintf(
            '%s-%s-%s',
            $tournament->startDate->format('Y-m-d'),
            $this->slugify($tournament->name),
            $this->slugify($tournament->location),
        );
        $filename = sprintf('%s.html', $base);
        $suffix = 2;

        while (isset($usedFilenames[$filename])) {
            $filename = sprintf('%s-%d.html', $base, $suffix);
            $suffix++;
        }

        $usedFilenames[$filename] = true;

        return $filename;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(strtr($value, [
            'Ą' => 'ą',
            'Ć' => 'ć',
            'Ę' => 'ę',
            'Ł' => 'ł',
            'Ń' => 'ń',
            'Ó' => 'ó',
            'Ś' => 'ś',
            'Ź' => 'ź',
            'Ż' => 'ż',
            'ą' => 'a',
            'ć' => 'c',
            'ę' => 'e',
            'ł' => 'l',
            'ń' => 'n',
            'ó' => 'o',
            'ś' => 's',
            'ź' => 'z',
            'ż' => 'z',
        ]));

        $value = (string) preg_replace('/[^a-z0-9]+/u', '-', $value);
        $value = trim($value, '-');

        return $value === '' ? 'unknown' : $value;
    }

    private function ensureDirectoryExists(string $outputDir): void
    {
        if (is_dir($outputDir)) {
            return;
        }

        if (!mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Could not create directory: %s', $outputDir));
        }
    }

    /**
     * @return positive-int|false
     */
    private function parseRequiredPositiveInt(mixed $value, string $name, SymfonyStyle $io): int|false
    {
        if (!is_scalar($value) || !preg_match('/^\d+$/', (string) $value) || (int) $value <= 0) {
            $io->error(sprintf('Option --%s must be a positive integer.', $name));

            return false;
        }

        return (int) $value;
    }

    /**
     * @return positive-int|null|false
     */
    private function parseOptionalPositiveInt(mixed $value, string $name, SymfonyStyle $io): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->parseRequiredPositiveInt($value, $name, $io);
    }
}
