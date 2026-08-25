<?php

declare(strict_types=1);

namespace App\Command\TournamentImport;

use App\PfsTournamentImport\ParsedTournamentRankingRow;
use App\Service\PfsNameNormalizer;
use App\Service\PfsTournamentResultsParser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:pfs:players:backfill-cities',
    description: 'One-time backfill for PFS player cities from tournament ranking sections.',
)]
final class BackfillPfsPlayerCitiesCommand extends Command
{
    private const string ORGANIZATION_CODE = 'PFS';
    private const int MAX_REPORT_ROWS = 200;
    private const string FIRST_EMBEDDED_RANKING_FILENAME = '2002-09-14-i-mistrzostwa-lublina-lublin.html';

    public function __construct(
        private readonly PfsTournamentResultsParser $resultsParser,
        private readonly PfsNameNormalizer $nameNormalizer,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from-year', null, InputOption::VALUE_REQUIRED, 'First PFS calendar year to inspect.')
            ->addOption('to-year', null, InputOption::VALUE_REQUIRED, 'Last PFS calendar year to inspect.', date('Y'))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview city updates and roll them back.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum tournament HTML files to inspect.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = $this->parseOptionalPositiveInt($input->getOption('limit'), 'limit', $io);
        if ($limit === false) {
            return Command::INVALID;
        }

        try {
            $organizationId = $this->loadPfsOrganizationId();
            $yearRange = $this->resolveYearRange($input, $organizationId);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $fromYear = $yearRange['from'];
        $toYear = $yearRange['to'];
        if ($fromYear > $toYear) {
            $io->error('Option --from-year must not be greater than --to-year.');

            return Command::INVALID;
        }

        $summary = [
            'tournamentHtmlFiles' => 0,
            'rankingMissingOnPage' => 0,
            'rankingRows' => 0,
            'playersObserved' => 0,
            'playersUpdated' => 0,
            'playerRowsUpdated' => 0,
            'ambiguousPlayers' => 0,
            'missingPlayers' => 0,
        ];

        try {
            $aggregate = $this->buildCityAggregate($fromYear, $toYear, $limit, $summary, $io);
            $localPlayersByName = $this->loadLocalPlayersByNormalizedName();

            [$ambiguousRows, $missingRows, $updates] = $this->classifyAggregate($aggregate, $localPlayersByName);
            $summary['playersObserved'] = count($aggregate);
            $summary['ambiguousPlayers'] = count($ambiguousRows);
            $summary['missingPlayers'] = count($missingRows);
            $summary['playersUpdated'] = count($updates);

            $this->connection->beginTransaction();
            try {
                foreach ($updates as $update) {
                    $affected = $this->connection->executeStatement(
                        'UPDATE player
                         SET city = :city
                         WHERE id IN (:playerIds)
                           AND city IS DISTINCT FROM :city',
                        [
                            'city' => $update['city'],
                            'playerIds' => $update['playerIds'],
                        ],
                        [
                            'playerIds' => ArrayParameterType::INTEGER,
                        ],
                    );
                    $summary['playerRowsUpdated'] += $affected;
                }

                if ($dryRun) {
                    $this->connection->rollBack();
                    $io->warning('Dry-run mode enabled: transaction rolled back.');
                } else {
                    $this->connection->commit();
                }
            } catch (\Throwable $exception) {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }

                throw $exception;
            }
        } catch (\Throwable $exception) {
            $io->error(sprintf('PFS player city backfill failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['Years inspected', sprintf('%d-%d', $fromYear, $toYear)],
                ['Tournament HTML files', (string) $summary['tournamentHtmlFiles']],
                ['Ranking missing/unparseable', (string) $summary['rankingMissingOnPage']],
                ['Ranking rows read', (string) $summary['rankingRows']],
                ['Players observed', (string) $summary['playersObserved']],
                ['Players resolved to one city', (string) $summary['playersUpdated']],
                ['Player rows updated', (string) $summary['playerRowsUpdated']],
                ['Players with multiple cities', (string) $summary['ambiguousPlayers']],
                ['Players missing in database', (string) $summary['missingPlayers']],
                ['Dry run', $dryRun ? 'yes' : 'no'],
            ],
        );

        $this->renderLimitedTable($io, 'Players with multiple cities', ['Player', 'Cities'], $ambiguousRows);
        $this->renderLimitedTable($io, 'Players missing in database', ['Player', 'Cities'], $missingRows);

        $io->success('PFS player city backfill completed.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, int> $summary
     * @return array<string, array{playerName:string,cities:array<string,string>}>
     */
    private function buildCityAggregate(
        int $fromYear,
        int $toYear,
        ?int $limit,
        array &$summary,
        SymfonyStyle $io,
    ): array {
        $aggregate = [];
        $htmlFiles = $this->listTournamentHtmlFiles($fromYear, $toYear);

        foreach ($htmlFiles as $htmlFile) {
            $summary['tournamentHtmlFiles']++;
            $html = $this->readTournamentHtml($htmlFile);
            if (!$this->hasNonEmptyRankingSection($html)) {
                $summary['rankingMissingOnPage']++;

                if ($this->limitReached($summary['tournamentHtmlFiles'], $limit)) {
                    break;
                }
                continue;
            }

            try {
                $rankingRows = $this->resultsParser->parseRankingRows($html);
            } catch (\Throwable $exception) {
                $summary['rankingMissingOnPage']++;
                $io->writeln(sprintf(
                    'Skipping %s: ranking not available or not parseable: %s',
                    basename($htmlFile),
                    $exception->getMessage(),
                ));

                if ($this->limitReached($summary['tournamentHtmlFiles'], $limit)) {
                    break;
                }
                continue;
            }

            $summary['rankingRows'] += count($rankingRows);
            $this->addRankingRowsToAggregate($aggregate, $rankingRows);

            if ($this->limitReached($summary['tournamentHtmlFiles'], $limit)) {
                break;
            }
        }

        ksort($aggregate);

        return $aggregate;
    }

    /**
     * @return list<string>
     */
    private function listTournamentHtmlFiles(int $fromYear, int $toYear): array
    {
        $htmlDir = sprintf('%s/tournament_html', rtrim($this->projectDir, '/'));
        if (!is_dir($htmlDir)) {
            throw new \RuntimeException(sprintf('Tournament HTML directory was not found: %s', $htmlDir));
        }

        $files = glob(sprintf('%s/*.html', $htmlDir));
        if ($files === false) {
            throw new \RuntimeException(sprintf('Could not list tournament HTML directory: %s', $htmlDir));
        }

        $filtered = [];
        foreach ($files as $file) {
            if (basename($file) < self::FIRST_EMBEDDED_RANKING_FILENAME) {
                continue;
            }

            $year = $this->parseYearFromTournamentHtmlFilename($file);
            if ($year === null || $year < $fromYear || $year > $toYear) {
                continue;
            }

            $filtered[] = $file;
        }

        sort($filtered, SORT_STRING);

        return $filtered;
    }

    private function parseYearFromTournamentHtmlFilename(string $file): ?int
    {
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}-.+\.html$/', basename($file), $match)) {
            return null;
        }

        return (int) $match[1];
    }

    private function readTournamentHtml(string $htmlFile): string
    {
        $html = file_get_contents($htmlFile);
        if (!is_string($html) || $html === '') {
            throw new \RuntimeException(sprintf('Could not read tournament HTML file: %s', $htmlFile));
        }

        return $html;
    }

    private function hasNonEmptyRankingSection(string $html): bool
    {
        foreach (['p_ranking', 'ranking'] as $containerId) {
            foreach (["'", '"'] as $quote) {
                $pattern = sprintf(
                    '~<div id=%s%s%s[^>]*>\s*<pre>(.*?)</pre>\s*</div>~su',
                    preg_quote($quote, '~'),
                    preg_quote($containerId, '~'),
                    preg_quote($quote, '~'),
                );

                if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($matches as $match) {
                    $text = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $text = str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], $text);
                    if (trim($text) !== '') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, array{playerName:string,cities:array<string,string>}> $aggregate
     * @param list<ParsedTournamentRankingRow> $rankingRows
     */
    private function addRankingRowsToAggregate(array &$aggregate, array $rankingRows): void
    {
        foreach ($rankingRows as $rankingRow) {
            $playerName = trim($rankingRow->playerName);
            $city = $this->normalizeCity($rankingRow->city);
            if ($playerName === '' || $city === null) {
                continue;
            }

            $playerKey = $this->nameNormalizer->normalizeForMatch($playerName);
            $cityKey = $this->nameNormalizer->normalizeForMatch($city);
            if ($playerKey === '' || $cityKey === '') {
                continue;
            }

            $aggregate[$playerKey] ??= [
                'playerName' => $playerName,
                'cities' => [],
            ];
            $aggregate[$playerKey]['cities'][$cityKey] ??= $city;
        }
    }

    /**
     * @return array<string, list<array{id:int,nameShow:string,city:string|null}>>
     */
    private function loadLocalPlayersByNormalizedName(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.name_show, p.city
             FROM player p
             WHERE p.name_show IS NOT NULL
             ORDER BY p.id ASC',
        );

        $players = [];
        foreach ($rows as $row) {
            $nameShow = (string) $row['name_show'];
            $key = $this->nameNormalizer->normalizeForMatch($nameShow);
            if ($key === '') {
                continue;
            }

            $players[$key][] = [
                'id' => (int) $row['id'],
                'nameShow' => $nameShow,
                'city' => isset($row['city']) ? (string) $row['city'] : null,
            ];
        }

        return $players;
    }

    /**
     * @param array<string, array{playerName:string,cities:array<string,string>}> $aggregate
     * @param array<string, list<array{id:int,nameShow:string,city:string|null}>> $localPlayersByName
     * @return array{0:list<array{0:string,1:string}>,1:list<array{0:string,1:string}>,2:list<array{playerName:string,city:string,playerIds:list<int>}>}
     */
    private function classifyAggregate(array $aggregate, array $localPlayersByName): array
    {
        $ambiguousRows = [];
        $missingRows = [];
        $updates = [];

        foreach ($aggregate as $playerKey => $entry) {
            $cities = array_values($entry['cities']);
            sort($cities, SORT_NATURAL | SORT_FLAG_CASE);

            if (count($cities) !== 1) {
                $ambiguousRows[] = [$entry['playerName'], implode(', ', $cities)];
            }

            if (!isset($localPlayersByName[$playerKey])) {
                $missingRows[] = [$entry['playerName'], implode(', ', $cities)];
                continue;
            }

            if (count($cities) !== 1) {
                continue;
            }

            $updates[] = [
                'playerName' => $entry['playerName'],
                'city' => $cities[0],
                'playerIds' => array_map(
                    static fn (array $player): int => (int) $player['id'],
                    $localPlayersByName[$playerKey],
                ),
            ];
        }

        usort($ambiguousRows, static fn (array $a, array $b): int => strcasecmp($a[0], $b[0]));
        usort($missingRows, static fn (array $a, array $b): int => strcasecmp($a[0], $b[0]));

        return [$ambiguousRows, $missingRows, $updates];
    }

    /**
     * @param list<array{0:string,1:string}> $rows
     */
    private function renderLimitedTable(SymfonyStyle $io, string $title, array $headers, array $rows): void
    {
        $io->section($title);
        if ($rows === []) {
            $io->writeln('None.');

            return;
        }

        $io->table($headers, array_slice($rows, 0, self::MAX_REPORT_ROWS));
        if (count($rows) > self::MAX_REPORT_ROWS) {
            $io->note(sprintf('Showing first %d of %d rows.', self::MAX_REPORT_ROWS, count($rows)));
        }
    }

    /**
     * @return positive-int|null|false
     */
    private function parseOptionalPositiveInt(mixed $value, string $name, SymfonyStyle $io): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_scalar($value) || !preg_match('/^\d+$/', (string) $value) || (int) $value <= 0) {
            $io->error(sprintf('Option --%s must be a positive integer.', $name));

            return false;
        }

        return (int) $value;
    }

    private function loadPfsOrganizationId(): int
    {
        $value = $this->connection->fetchOne(
            'SELECT id FROM organization WHERE UPPER(code) = :code LIMIT 1',
            ['code' => self::ORGANIZATION_CODE],
        );

        if ($value === false || $value === null) {
            throw new \RuntimeException('PFS organization was not found.');
        }

        return (int) $value;
    }

    /**
     * @return array{from:int,to:int}
     */
    private function resolveYearRange(InputInterface $input, int $organizationId): array
    {
        $fromYear = $input->getOption('from-year');
        if ($fromYear === null || $fromYear === '') {
            $fromYear = (string) ($this->resolveDownloadedHtmlYearBoundary(min: true) ?? $this->resolveImportedTournamentYearBoundary($organizationId, min: true));
        }

        $toYear = $input->getOption('to-year');
        if ($toYear === null || $toYear === '') {
            $toYear = (string) ($this->resolveDownloadedHtmlYearBoundary(min: false) ?? date('Y'));
        }

        foreach (['from-year' => $fromYear, 'to-year' => $toYear] as $name => $value) {
            if (!is_scalar($value) || !preg_match('/^\d{4}$/', (string) $value)) {
                throw new \RuntimeException(sprintf('Option --%s must be a four-digit year.', $name));
            }
        }

        return ['from' => (int) $fromYear, 'to' => (int) $toYear];
    }

    private function resolveDownloadedHtmlYearBoundary(bool $min): ?int
    {
        $htmlDir = sprintf('%s/tournament_html', rtrim($this->projectDir, '/'));
        if (!is_dir($htmlDir)) {
            return null;
        }

        $files = glob(sprintf('%s/*.html', $htmlDir));
        if ($files === false || $files === []) {
            return null;
        }

        $years = [];
        foreach ($files as $file) {
            $year = $this->parseYearFromTournamentHtmlFilename($file);
            if ($year !== null) {
                $years[] = $year;
            }
        }

        if ($years === []) {
            return null;
        }

        return $min ? min($years) : max($years);
    }

    private function resolveImportedTournamentYearBoundary(int $organizationId, bool $min): int
    {
        $dateExpression = $min ? 'MIN(dt)' : 'MAX(dt)';
        $date = $this->connection->fetchOne(
            sprintf('SELECT %s FROM tournament WHERE organization_id = :organizationId', $dateExpression),
            ['organizationId' => $organizationId],
        );

        if ($date === false || $date === null) {
            throw new \RuntimeException('No downloaded tournament HTML files or imported PFS tournaments were found.');
        }

        return (int) substr((string) $date, 0, 4);
    }

    private function normalizeCity(string $city): ?string
    {
        $city = (string) preg_replace('/\s+/u', ' ', trim($city));

        return $city === '' ? null : mb_substr($city, 0, 256);
    }

    private function limitReached(int $count, ?int $limit): bool
    {
        return $limit !== null && $count >= $limit;
    }
}
