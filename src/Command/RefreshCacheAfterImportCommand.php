<?php

namespace App\Command;

use App\Service\DatasetVersionService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsCommand(
    name: 'app:cache:refresh-after-import',
    description: 'Refresh app caches after importing a MySQL dump: clear (optional), bump dataset version, and warmup (optional).',
)]
final class RefreshCacheAfterImportCommand extends Command
{
    public function __construct(
        private readonly DatasetVersionService $datasetVersionService,
        private readonly KernelInterface $kernel,
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private readonly Connection $mysqlConnection,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cacheApp,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dataset-version', null, InputOption::VALUE_REQUIRED, 'Explicit dataset version value to set.')
            ->addOption('clear-cache-app', null, InputOption::VALUE_NONE, 'Clear cache.app pool before bumping version.')
            ->addOption('warmup', null, InputOption::VALUE_NONE, 'Warm selected public API endpoints after version bump.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('clear-cache-app')) {
            $io->writeln('Clearing cache.app pool...');
            $this->cacheApp->clear();
        }

        $currentVersion = $this->datasetVersionService->getVersion();
        $newVersion = $this->datasetVersionService->bumpVersion($this->stringOrNull($input->getOption('dataset-version')));
        $io->success(sprintf('Dataset version changed: %s -> %s', $currentVersion, $newVersion));

        if (!(bool) $input->getOption('warmup')) {
            return Command::SUCCESS;
        }

        @ini_set('memory_limit', '768M');

        $paths = $this->buildWarmupPaths();
        if ($paths === []) {
            $io->warning('No warmup paths resolved.');
            return Command::SUCCESS;
        }

        $ok = 0;
        $fail = 0;

        foreach ($paths as $path) {
            $request = Request::create($path, 'GET', server: [
                'HTTP_ACCEPT' => 'application/ld+json',
                'HTTP_HOST' => 'localhost',
            ]);

            try {
                $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
                $statusCode = $response->getStatusCode();
                if ($statusCode >= 200 && $statusCode < 300) {
                    $ok++;
                    $io->writeln(sprintf('<info>WARMED</info> %s (%d)', $path, $statusCode));
                } else {
                    $fail++;
                    $io->writeln(sprintf('<error>FAILED</error> %s (%d)', $path, $statusCode));
                }
            } catch (\Throwable $e) {
                $fail++;
                $io->writeln(sprintf('<error>FAILED</error> %s (%s)', $path, $e->getMessage()));
            }
        }

        if ($fail > 0) {
            $io->warning(sprintf('Warmup completed with errors. success=%d, failed=%d', $ok, $fail));
            return Command::SUCCESS;
        }

        $io->success(sprintf('Warmup completed. success=%d, failed=%d', $ok, $fail));
        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function buildWarmupPaths(): array
    {
        $paths = [
            '/api/ranking',
            '/api/old-rank',
            '/api/players',
            '/api/tournaments',
            '/api/clubs',
            '/api/stats/all-times-results',
            '/api/stats/games',
            '/api/stats/games-won',
            '/api/stats/tournaments',
            '/api/stats/avg-points-per-game',
            '/api/annotated-games?page=1',
        ];

        try {
            $playerId = $this->fetchAnyPlayerId();
            if ($playerId !== null) {
                $paths[] = sprintf('/api/players/%d', $playerId);
                $paths[] = sprintf('/api/players/%d/tournaments', $playerId);
                $paths[] = sprintf('/api/players/%d/rank-history', $playerId);
                $paths[] = sprintf('/api/players/%d/rank-history/milestones', $playerId);
                $paths[] = sprintf('/api/players/%d/game-balance', $playerId);
                $paths[] = sprintf('/api/players/%d/records/most-points', $playerId);
            }

            $tournamentId = $this->fetchLatestTournamentId();
            if ($tournamentId !== null) {
                $paths[] = sprintf('/api/tournaments/%d/details', $tournamentId);
                $paths[] = sprintf('/api/tournaments/%d/results', $tournamentId);
            }

            $summary = $this->fetchAnyTournamentPlayerPair();
            if ($summary !== null) {
                $paths[] = sprintf('/api/tournaments/%d/players/%d/summary', $summary['tournamentId'], $summary['playerId']);
            }

            $annotated = $this->fetchAnyAnnotatedGameKey();
            if ($annotated !== null) {
                $paths[] = sprintf('/api/games/%d-%d-%d', $annotated['tour'], $annotated['round'], $annotated['player1']);
            }
        } catch (Exception) {
            // Ignore warmup enrichment errors and keep base path list.
        }

        return array_values(array_unique($paths));
    }

    private function fetchAnyPlayerId(): ?int
    {
        $value = $this->mysqlConnection->fetchOne('SELECT id FROM PFSPLAYER ORDER BY id ASC LIMIT 1');
        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    private function fetchLatestTournamentId(): ?int
    {
        $value = $this->mysqlConnection->fetchOne('SELECT id FROM PFSTOURS ORDER BY dt DESC, id DESC LIMIT 1');
        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array{tournamentId: int, playerId: int}|null
     */
    private function fetchAnyTournamentPlayerPair(): ?array
    {
        $row = $this->mysqlConnection->fetchAssociative(
            'SELECT turniej AS tournamentId, player AS playerId FROM PFSTOURWYN ORDER BY turniej DESC, player ASC LIMIT 1'
        );

        if ($row === false) {
            return null;
        }

        return [
            'tournamentId' => (int) $row['tournamentId'],
            'playerId' => (int) $row['playerId'],
        ];
    }

    /**
     * @return array{tour: int, round: int, player1: int}|null
     */
    private function fetchAnyAnnotatedGameKey(): ?array
    {
        $row = $this->mysqlConnection->fetchAssociative(
            'SELECT tour, `round`, player1 FROM PFSGCG ORDER BY tour DESC, `round` DESC LIMIT 1'
        );

        if ($row === false) {
            return null;
        }

        return [
            'tour' => (int) $row['tour'],
            'round' => (int) $row['round'],
            'player1' => (int) $row['player1'],
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
