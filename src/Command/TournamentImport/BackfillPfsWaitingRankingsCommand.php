<?php

declare(strict_types=1);

namespace App\Command\TournamentImport;

use App\PfsTournamentImport\ParsedTournamentRankingRow;
use App\Ranking\Domain\RankingType;
use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServiceInterface;
use App\Service\PfsNameNormalizer;
use App\Service\PfsTournamentResultsParser;
use App\Service\PlayerCatalogService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:pfs:rankings:backfill-waiting-lists',
    description: 'One-time backfill for missing PFS waiting-list ranking rows with rtype = w and u.',
)]
final class BackfillPfsWaitingRankingsCommand extends Command
{
    private const string ORGANIZATION_CODE = 'PFS';
    private const string OLD_METHOD_START_TOURNAMENT_NAME = 'II Wielicki Turniej w Scrabble';
    private const string FIRST_EMBEDDED_RANKING_FILENAME = '2002-09-14-i-mistrzostwa-lublina-lublin.html';

    private ?\Iterator $oldMethodSnapshots = null;
    /** @var array<string, mixed>|null */
    private ?array $currentOldMethodSnapshot = null;

    public function __construct(
        private readonly PfsTournamentResultsParser $resultsParser,
        private readonly PlayerCatalogService $playerCatalogService,
        private readonly PfsNameNormalizer $nameNormalizer,
        private readonly OldMethodCurrentRankingServiceInterface $oldMethodCurrentRankingService,
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run the backfill and roll the transaction back.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum imported tournaments to update.');
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
            'importedMatched' => 0,
            'missingLocalTournament' => 0,
            'rankingMissingOnPage' => 0,
            'alreadyHadW' => 0,
            'insertedW' => 0,
            'alreadyHadU' => 0,
            'insertedU' => 0,
            'copiedUBeforeOldMethodStart' => 0,
            'createdPlayers' => 0,
            'doubledPlayersIgnored' => 0,
        ];

        $this->connection->beginTransaction();

        try {
            $catalog = $this->playerCatalogService->loadPlayerCatalog($organizationId);
            $oldMethodStart = $this->loadOldMethodStartTournament($organizationId);
            if ($oldMethodStart === null) {
                throw new \RuntimeException(sprintf(
                    'Old-method start tournament "%s" was not found in imported PFS tournaments.',
                    self::OLD_METHOD_START_TOURNAMENT_NAME,
                ));
            }

            foreach ($this->buildTournamentImportWork($organizationId, $fromYear, $toYear, $summary) as $workItem) {
                $localTournament = $workItem['localTournament'];
                $importedTournament = $workItem['importedTournament'];
                $htmlFile = $localTournament['path'];

                $summary['importedMatched']++;
                $tournamentId = (int) $importedTournament['id'];
                $insertedForTournament = 0;
                $atOrAfterOldMethodStart = $this->isAtOrAfterOldMethodStart($importedTournament, $oldMethodStart);
                $alreadyHadW = $this->hasRankingRows($organizationId, $tournamentId, RankingType::FUCKED_WAITING);
                $alreadyHadU = $this->hasRankingRows($organizationId, $tournamentId, RankingType::NORMAL_WAITING);
                $needsParsedRankingRows = !$alreadyHadW || (!$alreadyHadU && !$atOrAfterOldMethodStart);
                $rankingRows = null;

                if ($needsParsedRankingRows) {
                    $html = $this->readTournamentHtml($htmlFile);

                    try {
                        $rankingRows = $this->resultsParser->parseRankingRows($html);
                    } catch (\Throwable $exception) {
                        $summary['rankingMissingOnPage']++;
                        $io->writeln(sprintf(
                            'Skipping %s: ranking not available or not parseable: %s',
                            basename($htmlFile),
                            $exception->getMessage(),
                        ));
                        unset($html, $localTournament, $importedTournament);
                        gc_collect_cycles();
                        continue;
                    }
                    $rankingRows = $this->removeDoubledRankingPlayers(
                        $rankingRows,
                        $localTournament,
                        $importedTournament,
                        $summary,
                    );
                    unset($html);
                }

                if ($alreadyHadW) {
                    $summary['alreadyHadW']++;
                } else {
                    $inserted = $this->insertOfficialWaitingRows(
                        $organizationId,
                        $tournamentId,
                        $rankingRows ?? [],
                        $catalog,
                        $summary,
                    );
                    $summary['insertedW'] += $inserted;
                    $insertedForTournament += $inserted;
                }

                if ($alreadyHadU) {
                    $summary['alreadyHadU']++;
                } elseif (!$atOrAfterOldMethodStart) {
                    $inserted = $this->insertParsedWaitingRows(
                        $organizationId,
                        $tournamentId,
                        $rankingRows ?? [],
                        $catalog,
                        RankingType::NORMAL_WAITING,
                        $summary,
                    );
                    $summary['insertedU'] += $inserted;
                    $summary['copiedUBeforeOldMethodStart'] += $inserted;
                    $insertedForTournament += $inserted;
                } else {
                    $inserted = $this->insertOldMethodWaitingRows($organizationId, $tournamentId);
                    $summary['insertedU'] += $inserted;
                    $insertedForTournament += $inserted;
                }

                if ($limit !== null && $insertedForTournament > 0) {
                    $limit--;
                    if ($limit <= 0) {
                        unset($html, $rankingRows, $localTournament, $importedTournament);
                        gc_collect_cycles();
                        break;
                    }
                }

                unset($html, $rankingRows, $localTournament, $importedTournament);
                gc_collect_cycles();
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

            $io->error(sprintf('PFS waiting-list backfill failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['Years inspected', sprintf('%d-%d', $fromYear, $toYear)],
                ['Tournament HTML files', (string) $summary['tournamentHtmlFiles']],
                ['Imported tournaments matched', (string) $summary['importedMatched']],
                ['Missing local tournaments', (string) $summary['missingLocalTournament']],
                ['Ranking missing/unparseable', (string) $summary['rankingMissingOnPage']],
                ['Already had w', (string) $summary['alreadyHadW']],
                ['Inserted w rows', (string) $summary['insertedW']],
                ['Already had u', (string) $summary['alreadyHadU']],
                ['Inserted u rows', (string) $summary['insertedU']],
                ['Copied u before old-method start', (string) $summary['copiedUBeforeOldMethodStart']],
                ['Created players', (string) $summary['createdPlayers']],
                ['Doubled players ignored', (string) $summary['doubledPlayersIgnored']],
                ['Dry run', $dryRun ? 'yes' : 'no'],
            ],
        );
        $io->success('PFS waiting-list ranking backfill completed.');

        return Command::SUCCESS;
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

    /**
     * @return iterable<string>
     */
    private function iterateTournamentHtmlFiles(int $fromYear, int $toYear): iterable
    {
        $htmlDir = sprintf('%s/tournament_html', rtrim($this->projectDir, '/'));
        if (!is_dir($htmlDir)) {
            throw new \RuntimeException(sprintf('Tournament HTML directory was not found: %s', $htmlDir));
        }

        $iterator = new \DirectoryIterator($htmlDir);
        $bucketByFilename = [];

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'html') {
                continue;
            }

            $filename = $fileInfo->getFilename();
            if ($filename < self::FIRST_EMBEDDED_RANKING_FILENAME) {
                continue;
            }

            $year = $this->parseYearFromTournamentHtmlFilename($filename);
            if ($year === null || $year < $fromYear || $year > $toYear) {
                continue;
            }

            $bucketByFilename[$filename] = $fileInfo->getPathname();
        }

        ksort($bucketByFilename, SORT_STRING);

        foreach ($bucketByFilename as $path) {
            yield $path;
        }
    }

    private function parseYearFromTournamentHtmlFilename(string $file): ?int
    {
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}-.+\.html$/', basename($file), $match)) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * @param array<string, int> $summary
     * @return list<array{
     *   localTournament: array{path:string,filename:string,date:int,normalizedFilename:string},
     *   importedTournament: array{id:int,dt:int,name:string,fullname:string|null,place:string|null,urlid:int|null}
     * }>
     */
    private function buildTournamentImportWork(
        int $organizationId,
        int $fromYear,
        int $toYear,
        array &$summary,
    ): array {
        $workItems = [];

        foreach ($this->iterateTournamentHtmlFiles($fromYear, $toYear) as $htmlFile) {
            $summary['tournamentHtmlFiles']++;
            $localTournament = $this->parseTournamentHtmlFileMetadata($htmlFile);
            $importedTournament = $this->findImportedTournament($organizationId, $localTournament);
            if ($importedTournament === null) {
                $summary['missingLocalTournament']++;
                continue;
            }

            $workItems[] = [
                'localTournament' => $localTournament,
                'importedTournament' => $importedTournament,
            ];
        }

        usort(
            $workItems,
            static function (array $a, array $b): int {
                $left = $a['importedTournament'];
                $right = $b['importedTournament'];

                return [$left['dt'], $left['id']] <=> [$right['dt'], $right['id']];
            },
        );

        return $workItems;
    }

    /**
     * @return array{path:string,filename:string,date:int,normalizedFilename:string}
     */
    private function parseTournamentHtmlFileMetadata(string $htmlFile): array
    {
        $filename = basename($htmlFile);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})-(.+)\.html$/', $filename, $match)) {
            throw new \RuntimeException(sprintf('Unexpected tournament HTML filename: %s', $filename));
        }

        return [
            'path' => $htmlFile,
            'filename' => $filename,
            'date' => (int) sprintf('%s%s%s', $match[1], $match[2], $match[3]),
            'normalizedFilename' => $this->normalize($match[4]),
        ];
    }

    private function readTournamentHtml(string $htmlFile): string
    {
        $html = file_get_contents($htmlFile);
        if (!is_string($html) || $html === '') {
            throw new \RuntimeException(sprintf('Could not read tournament HTML file: %s', $htmlFile));
        }

        return $html;
    }

    private function resolveDownloadedHtmlYearBoundary(bool $min): ?int
    {
        $htmlDir = sprintf('%s/tournament_html', rtrim($this->projectDir, '/'));
        if (!is_dir($htmlDir)) {
            return null;
        }

        $years = [];
        foreach (new \DirectoryIterator($htmlDir) as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'html') {
                continue;
            }

            $filename = $fileInfo->getFilename();
            if ($filename < self::FIRST_EMBEDDED_RANKING_FILENAME) {
                continue;
            }

            $year = $this->parseYearFromTournamentHtmlFilename($filename);
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

    /**
     * @param array{path:string,filename:string,date:int,normalizedFilename:string} $localTournament
     * @return array{id:int,dt:int,name:string,fullname:string|null,place:string|null,urlid:int|null}|null
     */
    private function findImportedTournament(int $organizationId, array $localTournament): ?array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, dt, name, fullname, place, urlid
             FROM tournament
             WHERE organization_id = :organizationId
               AND dt = :date
             ORDER BY id ASC',
            [
                'organizationId' => $organizationId,
                'date' => $localTournament['date'],
            ],
        );
        if (count($rows) === 1) {
            return $this->normalizeTournamentRow($rows[0]);
        }

        $matches = [];
        foreach ($rows as $row) {
            $name = (string) ($row['fullname'] ?? $row['name'] ?? '');
            $place = (string) ($row['place'] ?? '');
            $normalizedName = $this->normalize($name);
            $normalizedPlace = $this->normalize($place);
            $normalizedFilename = $localTournament['normalizedFilename'];

            if ($normalizedName !== '' && str_contains($normalizedFilename, $normalizedName)) {
                $matches[] = $this->normalizeTournamentRow($row);
                continue;
            }

            if ($normalizedPlace !== '' && str_ends_with($normalizedFilename, $normalizedPlace)) {
                $matches[] = $this->normalizeTournamentRow($row);
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:int,dt:int,name:string,fullname:string|null,place:string|null,urlid:int|null}
     */
    private function normalizeTournamentRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'dt' => (int) $row['dt'],
            'name' => (string) ($row['name'] ?? ''),
            'fullname' => isset($row['fullname']) ? (string) $row['fullname'] : null,
            'place' => isset($row['place']) ? (string) $row['place'] : null,
            'urlid' => isset($row['urlid']) ? (int) $row['urlid'] : null,
        ];
    }

    private function hasRankingRows(int $organizationId, int $tournamentId, string $rankingType): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS(
                SELECT 1
                FROM ranking
                WHERE organization_id = :organizationId
                  AND tournament_id = :tournamentId
                  AND rtype = :rankingType
             )',
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
                'rankingType' => $rankingType,
            ],
        );
    }

    /**
     * @param list<ParsedTournamentRankingRow> $rankingRows
     * @param array{path:string,filename:string,date:int,normalizedFilename:string} $localTournament
     * @param array{id:int,dt:int,name:string,fullname:string|null,place:string|null,urlid:int|null} $importedTournament
     * @param array<string, int> $summary
     * @return list<ParsedTournamentRankingRow>
     */
    private function removeDoubledRankingPlayers(
        array $rankingRows,
        array $localTournament,
        array $importedTournament,
        array &$summary,
    ): array {
        /** @var array<string, ParsedTournamentRankingRow> $seenByPlayerName */
        $seenByPlayerName = [];
        $filteredRows = [];

        foreach ($rankingRows as $rankingRow) {
            $normalizedName = $this->nameNormalizer->normalizeForMatch($rankingRow->playerName);
            if (isset($seenByPlayerName[$normalizedName])) {
                $this->appendDoubledPlayer(
                    $localTournament,
                    $importedTournament,
                    $seenByPlayerName[$normalizedName],
                    $rankingRow,
                );
                $summary['doubledPlayersIgnored']++;

                continue;
            }

            $seenByPlayerName[$normalizedName] = $rankingRow;
            $filteredRows[] = $rankingRow;
        }

        return $filteredRows;
    }

    /**
     * @param array{path:string,filename:string,date:int,normalizedFilename:string} $localTournament
     * @param array{id:int,dt:int,name:string,fullname:string|null,place:string|null,urlid:int|null} $importedTournament
     */
    private function appendDoubledPlayer(
        array $localTournament,
        array $importedTournament,
        ParsedTournamentRankingRow $firstOccurrence,
        ParsedTournamentRankingRow $ignoredOccurrence,
    ): void {
        $path = sprintf('%s/doubled_players.md', rtrim($this->projectDir, '/'));
        $needsHeader = !is_file($path) || filesize($path) === 0;
        $lines = [];

        if ($needsHeader) {
            $lines[] = '# Doubled Players';
            $lines[] = '';
            $lines[] = 'Rows skipped by app:pfs:rankings:backfill-waiting-lists because the same player name'
                . ' appeared more than once in one parsed ranking.';
            $lines[] = '';
        }

        $lines[] = sprintf(
            '## %s (%d, tournament_id=%d)',
            $importedTournament['fullname'] ?? $importedTournament['name'],
            $importedTournament['dt'],
            $importedTournament['id'],
        );
        $lines[] = '';
        $lines[] = sprintf('- HTML file: `%s`', $localTournament['filename']);
        $lines[] = sprintf('- Player name: %s', $ignoredOccurrence->playerName);
        $lines[] = sprintf(
            '- Kept: position %d, city "%s", rank %.2f, scalp %d, games %d',
            $firstOccurrence->position,
            $firstOccurrence->city,
            $firstOccurrence->rank,
            $firstOccurrence->scalp,
            $firstOccurrence->games,
        );
        $lines[] = sprintf(
            '- Ignored: position %d, city "%s", rank %.2f, scalp %d, games %d',
            $ignoredOccurrence->position,
            $ignoredOccurrence->city,
            $ignoredOccurrence->rank,
            $ignoredOccurrence->scalp,
            $ignoredOccurrence->games,
        );
        $lines[] = '';

        if (file_put_contents($path, implode("\n", $lines), FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Could not write doubled players report: %s', $path));
        }
    }

    /**
     * @param list<ParsedTournamentRankingRow> $rankingRows
     * @param list<array<string, mixed>> $catalog
     * @param array<string, int> $summary
     */
    private function insertOfficialWaitingRows(
        int $organizationId,
        int $tournamentId,
        array $rankingRows,
        array &$catalog,
        array &$summary,
    ): int {
        return $this->insertParsedWaitingRows(
            $organizationId,
            $tournamentId,
            $rankingRows,
            $catalog,
            RankingType::FUCKED_WAITING,
            $summary,
        );
    }

    /**
     * @param list<ParsedTournamentRankingRow> $rankingRows
     * @param list<array<string, mixed>> $catalog
     * @param array<string, int> $summary
     */
    private function insertParsedWaitingRows(
        int $organizationId,
        int $tournamentId,
        array $rankingRows,
        array &$catalog,
        string $rankingType,
        array &$summary,
    ): int {
        $inserted = 0;

        foreach ($rankingRows as $rankingRow) {
            if ($rankingRow->main) {
                continue;
            }

            // looks like this happens for players that changed city etc.
            if ($rankingRow->rank === 0.0 || $rankingRow->games === 0 || $rankingRow->scalp === 0) {
                continue;
            }

            $player = $this->resolveOrCreatePlayer($organizationId, $catalog, $rankingRow->playerName, $summary);

            $this->connection->insert('ranking', [
                'organization_id' => $organizationId,
                'rtype' => $rankingType,
                'player_id' => (int) $player['playerId'],
                'tournament_id' => $tournamentId,
                'position' => $rankingRow->position,
                'rank' => round($rankingRow->rank, 2),
                'games' => $rankingRow->games,
            ]);

            $inserted++;
        }

        return $inserted;
    }

    private function insertOldMethodWaitingRows(int $organizationId, int $tournamentId): int
    {
        $snapshot = $this->nextOldMethodSnapshot($tournamentId);
        if ($snapshot === null) {
            return 0;
        }

        $inserted = 0;
        foreach ($snapshot['waitingRows'] ?? [] as $row) {
            $this->connection->insert('ranking', [
                'organization_id' => $organizationId,
                'rtype' => RankingType::NORMAL_WAITING,
                'player_id' => (int) $row['playerId'],
                'tournament_id' => $tournamentId,
                'position' => (int) $row['position'],
                'rank' => round((float) $row['rankExact'], 2),
                'games' => (int) $row['games'],
            ]);
            $inserted++;
        }

        return $inserted;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nextOldMethodSnapshot(int $tournamentId): ?array
    {
        if ($this->oldMethodSnapshots === null) {
            $this->oldMethodSnapshots = $this->toIterator($this->oldMethodCurrentRankingService->calculateRankingSnapshots());
            $this->oldMethodSnapshots->rewind();
            $this->currentOldMethodSnapshot = $this->oldMethodSnapshots->valid()
                ? $this->normalizeOldMethodSnapshot($this->oldMethodSnapshots->current())
                : null;
        }

        while (
            $this->currentOldMethodSnapshot !== null
            && (int) $this->currentOldMethodSnapshot['referenceTournamentId'] < $tournamentId
        ) {
            $this->oldMethodSnapshots->next();
            $this->currentOldMethodSnapshot = $this->oldMethodSnapshots->valid()
                ? $this->normalizeOldMethodSnapshot($this->oldMethodSnapshots->current())
                : null;
            gc_collect_cycles();
        }

        if (
            $this->currentOldMethodSnapshot === null
            || (int) $this->currentOldMethodSnapshot['referenceTournamentId'] !== $tournamentId
        ) {
            return null;
        }

        return $this->currentOldMethodSnapshot;
    }

    private function toIterator(iterable $snapshots): \Iterator
    {
        if ($snapshots instanceof \Iterator) {
            return $snapshots;
        }

        if ($snapshots instanceof \IteratorAggregate) {
            return $snapshots->getIterator();
        }

        return new \ArrayIterator($snapshots);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeOldMethodSnapshot(mixed $snapshot): array
    {
        if (!is_array($snapshot)) {
            throw new \RuntimeException('Old-method ranking snapshot must be an array.');
        }

        return $snapshot;
    }

    /**
     * @return array{id:int,dt:int}|null
     */
    private function loadOldMethodStartTournament(int $organizationId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, dt
             FROM tournament
             WHERE organization_id = :organizationId
               AND LOWER(COALESCE(fullname, name, \'\')) = LOWER(:name)
             ORDER BY dt ASC, id ASC
             LIMIT 1',
            [
                'organizationId' => $organizationId,
                'name' => self::OLD_METHOD_START_TOURNAMENT_NAME,
            ],
        );

        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'dt' => (int) $row['dt']];
    }

    /**
     * @param array{id:int,dt:int,name:string,fullname:string|null,place:string|null,urlid:int|null} $importedTournament
     * @param array{id:int,dt:int} $oldMethodStart
     */
    private function isAtOrAfterOldMethodStart(array $importedTournament, array $oldMethodStart): bool
    {
        if ($importedTournament['dt'] !== $oldMethodStart['dt']) {
            return $importedTournament['dt'] > $oldMethodStart['dt'];
        }

        return $importedTournament['id'] >= $oldMethodStart['id'];
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @return array<string, mixed>|null
     */
    private function resolvePlayer(array $catalog, string $playerName): ?array
    {
        $normalized = $this->nameNormalizer->normalizeForMatch($playerName);
        foreach ($catalog as $player) {
            if (($player['nameShow'] ?? null) === $playerName || ($player['normalized'] ?? null) === $normalized) {
                return $player;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array<string, int> $summary
     * @return array<string, mixed>
     */
    private function resolveOrCreatePlayer(
        int $organizationId,
        array &$catalog,
        string $playerName,
        array &$summary,
    ): array {
        $player = $this->resolvePlayer($catalog, $playerName);
        if ($player !== null) {
            return $player;
        }

        $player = $this->createPlayer($organizationId, $playerName);
        $catalog[] = $player;
        $summary['createdPlayers']++;

        return $player;
    }

    /**
     * @return array<string, mixed>
     */
    private function createPlayer(int $organizationId, string $nameShow): array
    {
        $nameShow = $this->trimToLength($nameShow, 40);
        $nameAlph = $this->trimToLength($this->nameNormalizer->toAlphabeticalName($nameShow), 40);

        $firstName = null;
        $lastName = null;
        $nameParts = preg_split('/\s+/u', $nameShow) ?: [];
        if (count($nameParts) === 2) {
            $firstName = $nameParts[0];
            $lastName = $nameParts[1];
        }

        $playerId = (int) $this->connection->fetchOne(
            'INSERT INTO player (name_show, name_alph, first_name, last_name, slug)
             VALUES (:nameShow, :nameAlph, :firstName, :lastName, :slug)
             RETURNING id',
            [
                'nameShow' => $nameShow,
                'nameAlph' => $nameAlph,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'slug' => $this->buildSlug($nameShow),
            ],
        );

        $this->connection->executeStatement(
            'INSERT INTO player_organization (player_id, organization_id)
             VALUES (:playerId, :organizationId)
             ON CONFLICT DO NOTHING',
            [
                'playerId' => $playerId,
                'organizationId' => $organizationId,
            ],
        );

        return [
            'playerId' => $playerId,
            'nameShow' => $nameShow,
            'nameAlph' => $nameAlph,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'normalized' => $this->nameNormalizer->normalizeForMatch($nameShow),
        ];
    }

    private function buildSlug(string $name): ?string
    {
        return $this->slugifyPart($name);
    }

    private function slugifyPart(string $value): ?string
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

        return $value === '' ? null : $value;
    }

    private function trimToLength(string $value, int $limit): string
    {
        $value = trim($value);

        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit);
    }

    private function normalize(string $value): string
    {
        return $this->nameNormalizer->normalizeForMatch($value);
    }
}
