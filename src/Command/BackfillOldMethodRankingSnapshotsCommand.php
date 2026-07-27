<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServiceInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:ranking:backfill-old-method-snapshots',
    description: 'Backfill simulated old-method ranking snapshots into ranking rows with rtype = n.',
)]
final class BackfillOldMethodRankingSnapshotsCommand extends Command
{
    private const string RANKING_TYPE = 'n';

    public function __construct(
        private readonly OldMethodCurrentRankingServiceInterface $oldMethodCurrentRankingService,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calculate and write rows, then roll the transaction back.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $snapshotCount = 0;
        $insertedRows = 0;
        $deletedRows = 0;
        $organizationId = null;
        $firstTournamentId = null;
        $lastTournamentId = null;

        $this->connection->beginTransaction();

        try {
            foreach ($this->oldMethodCurrentRankingService->calculateRankingSnapshots() as $snapshot) {
                $snapshotCount++;
                $organizationId ??= (int) $snapshot['organizationId'];
                $tournamentId = (int) $snapshot['referenceTournamentId'];
                $firstTournamentId ??= $tournamentId;
                $lastTournamentId = $tournamentId;

                if ($snapshotCount === 1) {
                    $deletedRows = $this->deleteExistingRows($organizationId);
                }

                foreach ($snapshot['rows'] as $row) {
                    $this->connection->insert('ranking', [
                        'organization_id' => $organizationId,
                        'rtype' => self::RANKING_TYPE,
                        'player_id' => (int) $row['playerId'],
                        'tournament_id' => $tournamentId,
                        'position' => (int) $row['position'],
                        'rank' => round((float) $row['rankExact'], 2),
                        'games' => (int) $row['games'],
                    ]);

                    $insertedRows++;
                }

                if ($snapshotCount % 25 === 0) {
                    $io->writeln(sprintf(
                        'Processed %d snapshots, inserted %d rows...',
                        $snapshotCount,
                        $insertedRows
                    ));
                }
            }

            if ($snapshotCount === 0 || $organizationId === null) {
                $this->connection->rollBack();
                $io->warning('No old-method ranking snapshots were calculated.');

                return Command::SUCCESS;
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

            $io->error(sprintf('Old-method ranking snapshot backfill failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['Organization ID', (string) $organizationId],
                ['Ranking type', self::RANKING_TYPE],
                ['Snapshots', (string) $snapshotCount],
                ['Inserted ranking rows', (string) $insertedRows],
                ['Deleted previous rows', (string) $deletedRows],
                ['First tournament ID', (string) $firstTournamentId],
                ['Last tournament ID', (string) $lastTournamentId],
                ['Dry run', $dryRun ? 'yes' : 'no'],
            ],
        );

        $io->success('Old-method ranking snapshots backfill completed.');

        return Command::SUCCESS;
    }

    private function deleteExistingRows(int $organizationId): int
    {
        return $this->connection->executeStatement(
            'DELETE FROM ranking WHERE organization_id = :organizationId AND rtype = :rtype',
            [
                'organizationId' => $organizationId,
                'rtype' => self::RANKING_TYPE,
            ],
        );
    }
}
