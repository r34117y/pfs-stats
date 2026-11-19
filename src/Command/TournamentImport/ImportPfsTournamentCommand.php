<?php

namespace App\Command\TournamentImport;

use App\PfsTournamentImport\CalendarTournament;
use App\Service\PfsTournamentCalendarParser;
use App\Service\PfsTournamentPayloadFactory;
use App\Service\PfsTournamentResultsParser;
use App\Service\PfsTournamentWebsiteClient;
use App\Service\RefreshCacheAfterImportLauncher;
use App\Service\TournamentRoundImportService;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:pfs:tournaments:import',
    description: 'Imports one PFS tournament page into the database.',
)]
final class ImportPfsTournamentCommand extends Command
{
    public function __construct(
        private readonly PfsTournamentWebsiteClient $websiteClient,
        private readonly PfsTournamentCalendarParser $calendarParser,
        private readonly PfsTournamentResultsParser $resultsParser,
        private readonly PfsTournamentPayloadFactory $payloadFactory,
        private readonly TournamentRoundImportService $importService,
        private readonly RefreshCacheAfterImportLauncher $refreshCacheAfterImportLauncher,
        private readonly ?\App\Service\PfsTournamentArchive $archive = null,
        private readonly ?\App\Service\PfsTournamentImportPreflight $preflight = null,
        private readonly ?\App\Service\DatasetVersionService $datasetVersion = null,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly ?\Doctrine\DBAL\Connection $connection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pfsid', null, InputOption::VALUE_REQUIRED, 'PFS tournament URL id.')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Calendar year for the tournament.', date('Y'))
            ->addOption('no-cache-warmup', null, InputOption::VALUE_NONE, 'Skip launching cache warmup after import.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pfsId = (int) $input->getOption('pfsid');
        $year = (int) $input->getOption('year');

        if (!preg_match('/^[1-9][0-9]*$/D', (string) $input->getOption('pfsid')) || filter_var($input->getOption('pfsid'), FILTER_VALIDATE_INT) === false) {
            $io->error('Option --pfsid must be a positive integer.');

            return Command::INVALID;
        }

        if (!preg_match('/^[1-9][0-9]{3}$/D', (string) $input->getOption('year'))) {
            $io->error('Option --year must be a positive integer.');

            return Command::INVALID;
        }

        try {
            $organizationId = null;
            $calendarTournament = $this->fetchCalendarTournament($year, $pfsId);
            if ($calendarTournament->endDate >= new \DateTimeImmutable('today')) { throw new RuntimeException('Tournament has not ended; completed results are required.'); }
            $source = $this->archive?->load($calendarTournament);
            $parsedResults = $source?->results ?? (new \App\Service\PfsTournamentSourceValidator($this->resultsParser))->validate($this->websiteClient->fetchTournamentHtml($calendarTournament->urlId));
            $payload = $this->payloadFactory->create($calendarTournament, $parsedResults);
            $unchanged = false;
            if ($source !== null && $this->preflight !== null && $this->connection !== null) {
                $organizationId = $this->preflight->check($source)->organizationId;
                $importResult = $this->connection->transactional(function () use ($source, $payload, &$unchanged) {
                    $org = (int) $this->connection->fetchOne("SELECT id FROM organization WHERE lower(code) = 'pfs'");
                    $this->connection->executeStatement('SELECT pg_advisory_xact_lock(73673, ?)', [$org]);
                    $check = $this->preflight->check($source);
                    if ($check->existingTournamentId !== null) {
                        $differences = (new \App\Service\PfsImportVerification\ImportedTournamentComparator($this->connection))->compare($source, $org, $check->existingTournamentId);
                        if ($differences) { throw new RuntimeException('blocked: existing import differs from source: ' . json_encode(array_slice($differences, 0, 3), JSON_UNESCAPED_UNICODE)); }
                        $unchanged = true;
                        return new \App\Service\TournamentRoundImportResult($check->existingTournamentId, []);
                    }
                    return $this->importService->import($payload, $check->confirmedDebutants);
                });
            } else {
                $importResult = $this->importService->import($payload);
            }
        } catch (Throwable $exception) {
            $outcome = $exception instanceof \LogicException || $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpException || ($exception instanceof RuntimeException && !str_contains($exception->getMessage(), 'Could not fetch remote URL')) ? \App\PfsTournamentImport\PfsTournamentImportOutcome::Blocked : \App\PfsTournamentImport\PfsTournamentImportOutcome::Failed;
            $io->error(sprintf('%s: Could not import PFS tournament: %s', $outcome->value, $exception->getMessage()));

            return Command::FAILURE;
        }

        if ($unchanged) {
            $io->success(sprintf('unchanged: PFS %d is complete as local tournament %d; 0 writes, no cache refresh.', $pfsId, $importResult->tournamentId));
            return Command::SUCCESS;
        }
        try {
            $version = $this->datasetVersion?->bumpVersion();
        } catch (Throwable $exception) {
            $io->error(sprintf('committed-cache-error: tournament %d (PFS %d) is COMMITTED; %s. Repair with app:cache:refresh-after-import, then resume.', $importResult->tournamentId, $pfsId, $exception->getMessage()));
            return Command::FAILURE;
        }
        if (!$input->getOption('no-cache-warmup') && !$this->refreshCacheAfterImportLauncher->launchWarmup($version ?? null, $organizationId)) {
            $io->warning('Dataset published; optional warmup could not be launched.');
        }
        if ($source !== null) { $io->writeln('Source: ' . $source->path . ' SHA-256 ' . $source->sha256); }
        foreach ($parsedResults->warnings as $warning) { $io->warning(json_encode($warning, JSON_UNESCAPED_UNICODE)); }
        $io->success(sprintf(
            'imported: %s as tournament with id %d.',
            $parsedResults->tournamentName,
            $importResult->tournamentId,
        ));
        $io->table(
            ['Metric', 'Value'],
            [
                ['Tournament id', $importResult->tournamentId],
                ['PFS id', (string) $calendarTournament->urlId],
                ['Date', $calendarTournament->startDate->format('Y-m-d')],
                ['City', $calendarTournament->location],
                ['Players', (string) count($payload->players)],
                ['Games', (string) count(array_filter($payload->results, static fn (array $game): bool => ($game['rankingEligible'] ?? true) === true))],
                ['Round entries including byes/forfeits', (string) count($payload->results)],
                ['Ranking rows f', (string) $importResult->rankingRowsInserted('f')],
                ['Ranking rows w', (string) $importResult->rankingRowsInserted('w')],
                ['Ranking rows n', (string) $importResult->rankingRowsInserted('n')],
                ['Ranking rows u', (string) $importResult->rankingRowsInserted('u')],
            ],
        );

        return Command::SUCCESS;
    }

    private function fetchCalendarTournament(int $year, int $urlId): CalendarTournament
    {
        $calendarHtml = $this->websiteClient->fetchCalendarHtml($year);
        $tournaments = $this->calendarParser->parse($calendarHtml, $year);

        foreach ($tournaments as $tournament) {
            if ($tournament->urlId === $urlId) {
                return $tournament;
            }
        }

        throw new RuntimeException(sprintf('Tournament with PFS id %d was not found in calendar year %d.', $urlId, $year));
    }
}
