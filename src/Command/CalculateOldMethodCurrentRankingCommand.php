<?php

namespace App\Command;

use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ranking:old-method-current',
    description: 'Calculates and displays current ranking as if old PFS method had always been used.',
)]
final class CalculateOldMethodCurrentRankingCommand extends Command
{
    public function __construct(
        private readonly OldMethodCurrentRankingService $oldMethodCurrentRankingService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->oldMethodCurrentRankingService->calculateCurrentRanking();
        if ($result['referenceTournamentId'] === 0) {
            $io->warning('No ranking snapshots found in PFSRANKING (rtype = f).');

            return Command::SUCCESS;
        }

        $io->title('Current ranking simulated with old method');
        $io->writeln(sprintf(
            'Reference tournament: <info>%d</info> (<comment>%s</comment>)',
            $result['referenceTournamentId'],
            $result['referenceTournamentName']
        ));
        $io->writeln(sprintf(
            'Reference date: <info>%s</info>, 2-year window start: <info>%s</info>',
            $result['referenceDate'],
            $result['windowStartDate']
        ));
        $io->newLine();

        if ($result['rows'] === []) {
            $io->warning('No players met the old-method criteria (>=30 games inside capped 200-game window).');

            return Command::SUCCESS;
        }

        $tableRows = [];
        foreach ($result['rows'] as $row) {
            $tableRows[] = [
                $row['position'],
                $row['playerId'],
                $row['playerName'],
                number_format($row['rankExact'], 2, '.', ''),
                $row['games'],
                $row['tournaments'],
            ];
        }

        $io->table(
            ['Pos', 'Player ID', 'Player', 'Rank', 'Games', 'Tournaments'],
            $tableRows
        );

        $io->success(sprintf('Calculated %d ranking rows.', count($result['rows'])));

        return Command::SUCCESS;
    }
}
