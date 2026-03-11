<?php

namespace App\Command;

use App\Service\PfsTournamentImportCheck\PfsTournamentImportCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:pfs:tournaments:check-imports',
    description: 'Checks the PFS calendar and reports finished tournaments that are not imported yet.',
)]
final class CheckPfsTournamentImportsCommand extends Command
{
    public function __construct(
        private readonly PfsTournamentImportCheckService $checkService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('year', null, InputOption::VALUE_REQUIRED, 'Calendar year to inspect.', (string) date('Y'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $year = (int) $input->getOption('year');

        if ($year < 2000 || $year > 2100) {
            $io->error(sprintf('Unsupported year: %d', $year));

            return Command::INVALID;
        }

        try {
            $result = $this->checkService->check($year);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Could not check tournament imports: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->title(sprintf('PFS Tournament Import Check (%d)', $result->year));
        $io->writeln(sprintf(
            'Latest imported tournament id: %s',
            $result->latestImportedTournamentId !== null ? (string) $result->latestImportedTournamentId : 'none'
        ));

        if ($result->pendingImports === []) {
            $io->success('No finished tournaments pending import.');

            return Command::SUCCESS;
        }

        $io->warning(sprintf('Found %d finished tournament(s) pending import.', count($result->pendingImports)));
        $io->table(
            ['Inferred DB id', 'PFS urlid', 'End date', 'Name', 'Results URL'],
            array_map(
                static fn ($pendingImport): array => [
                    (string) $pendingImport->inferredId,
                    (string) $pendingImport->urlId,
                    $pendingImport->endDate->format('Y-m-d'),
                    $pendingImport->name,
                    $pendingImport->getResultsUrl(),
                ],
                $result->pendingImports,
            ),
        );

        foreach ($result->pendingImports as $pendingImport) {
            $io->section(sprintf(
                '%s (%s, inferred id %d)',
                $pendingImport->name,
                $pendingImport->endDate->format('Y-m-d'),
                $pendingImport->inferredId,
            ));
            $io->writeln(sprintf('Parsed players: %d', count($pendingImport->results->players)));
            $referee = $pendingImport->results->getDetailValue('Sędzia');
            if ($referee !== null) {
                $io->writeln(sprintf('Referee: %s', $referee));
            }

            $firstPlayer = $pendingImport->results->players[0] ?? null;
            if ($firstPlayer !== null) {
                $io->writeln(sprintf(
                    'First player: %s, games=%d, scalp=%d, achieved rank=%.2f',
                    $firstPlayer->playerName,
                    count($firstPlayer->games),
                    $firstPlayer->totalScalp,
                    $firstPlayer->rankAchieved,
                ));
            }
        }

        return Command::SUCCESS;
    }
}
