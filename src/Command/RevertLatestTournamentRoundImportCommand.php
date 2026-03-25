<?php

namespace App\Command;

use App\Service\RefreshCacheAfterImportLauncher;
use App\Service\TournamentRoundRollbackService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:tournament-round:revert-latest-import',
    description: 'Revert the most recent tournament round import.',
)]
final class RevertLatestTournamentRoundImportCommand extends Command
{
    public function __construct(
        private readonly TournamentRoundRollbackService $tournamentRoundRollbackService,
        private readonly RefreshCacheAfterImportLauncher $refreshCacheAfterImportLauncher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $summary = $this->tournamentRoundRollbackService->revertMostRecentImport();
        } catch (Throwable $exception) {
            $io->error(sprintf('Could not revert latest tournament round import: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Reverted tournament import %d%s.',
            $summary['tournamentId'],
            $summary['tournamentName'] !== null ? sprintf(' (%s)', $summary['tournamentName']) : '',
        ));

        $io->table(
            ['Metric', 'Value'],
            [
                ['Organization id', (string) $summary['organizationId']],
                ['Tournament legacy id', (string) $summary['tournamentId']],
                ['Tournament row id', (string) $summary['tournamentDbId']],
                ['Ranking rows deleted', (string) $summary['rankingDeleted']],
                ['Tournament result rows deleted', (string) $summary['tournamentResultsDeleted']],
                ['Tournament game rows deleted', (string) $summary['tournamentGamesDeleted']],
                ['Tournament rows deleted', (string) $summary['tournamentDeleted']],
                ['Audit rows deleted', (string) $summary['auditDeleted']],
                ['Created player organization rows deleted', (string) $summary['createdPlayerOrganizationsDeleted']],
                ['Created players deleted', (string) $summary['createdPlayersDeleted']],
            ],
        );

        if ($summary['skippedCreatedPlayerIds'] !== []) {
            $io->warning(sprintf(
                'Some created players were kept because they still have references or associations: %s',
                implode(', ', $summary['skippedCreatedPlayerIds']),
            ));
        }

        $this->refreshCacheAfterImportLauncher->launchWarmup();

        return Command::SUCCESS;
    }
}
