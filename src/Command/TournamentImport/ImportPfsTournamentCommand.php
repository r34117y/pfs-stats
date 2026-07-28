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

        if ($pfsId <= 0) {
            $io->error('Option --pfsid must be a positive integer.');

            return Command::INVALID;
        }

        if ($year <= 0) {
            $io->error('Option --year must be a positive integer.');

            return Command::INVALID;
        }

        try {
            $calendarTournament = $this->fetchCalendarTournament($year, $pfsId);
            $html = $this->websiteClient->fetchTournamentHtml($calendarTournament->urlId);
            $parsedResults = $this->resultsParser->parse($html);
            $payload = $this->payloadFactory->create($calendarTournament, $parsedResults);
            $tournamentId = $this->importService->import($payload);

            if (!$input->getOption('no-cache-warmup')) {
                $this->refreshCacheAfterImportLauncher->launchWarmup();
            }
        } catch (Throwable $exception) {
            $io->error(sprintf('Could not import PFS tournament: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Imported %s as tournament with id %d.',
            $parsedResults->tournamentName,
            $tournamentId,
        ));
        $io->table(
            ['Metric', 'Value'],
            [
                ['Tournament id', $tournamentId],
                ['PFS id', (string) $calendarTournament->urlId],
                ['Date', $calendarTournament->startDate->format('Y-m-d')],
                ['City', $calendarTournament->location],
                ['Players', (string) count($payload->players)],
                ['Games', (string) count($payload->results)],
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
