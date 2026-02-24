<?php

namespace App\Command;

use App\GcgParser\GcgParser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:gcg:validate-db',
    description: 'Parses all PFSGCG records and stores only failing GCGs in {projectRoot}/gcg.',
)]
final class ValidateDatabaseGcgCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Filesystem $filesystem,
        private readonly GcgParser $gcgParser,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->connection->executeQuery(
            'SELECT tour, `round`, player1, data FROM PFSGCG ORDER BY tour ASC, `round` ASC, player1 ASC'
        );

        $failedCount = 0;
        $gcgDirectory = $this->projectDir . '/gcg';
        $gcgDirectoryReady = false;

        while (($row = $result->fetchAssociative()) !== false) {
            $tour = (int) $row['tour'];
            $round = (int) $row['round'];
            $player1 = (int) $row['player1'];
            $data = (string) $row['data'];

            try {
                $this->gcgParser->parse($data);
            } catch (\Throwable $exception) {
                $failedCount++;

                if (!$gcgDirectoryReady) {
                    try {
                        $this->filesystem->mkdir($gcgDirectory);
                    } catch (\Throwable $mkdirException) {
                        $io->error(sprintf('Could not create directory "%s": %s', $gcgDirectory, $mkdirException->getMessage()));

                        return Command::FAILURE;
                    }

                    $gcgDirectoryReady = true;
                }

                $io->warning(sprintf(
                    'Failed parsing GCG: tour=%d, round=%d, player1=%d (%s)',
                    $tour,
                    $round,
                    $player1,
                    $exception->getMessage(),
                ));

                $filePath = sprintf('%s/%d_%d_%d.gcg', $gcgDirectory, $tour, $round, $player1);

                try {
                    $this->filesystem->dumpFile($filePath, $data);
                } catch (\Throwable $writeException) {
                    $io->error(sprintf('Failed to write "%s": %s', $filePath, $writeException->getMessage()));

                    return Command::FAILURE;
                }
            }
        }

        $io->success(sprintf('Finished. Failing GCG count: %d', $failedCount));

        return Command::SUCCESS;
    }
}
