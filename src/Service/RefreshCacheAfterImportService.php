<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class RefreshCacheAfterImportService
{
    public function __construct(
        private DatasetVersionService $datasetVersionService,
        private KernelInterface $kernel,
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $mysqlConnection,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cacheApp,
    ) {
    }

    public function refresh(?string $datasetVersion = null, bool $clearCacheApp = false, bool $warmup = false, ?callable $reporter = null): RefreshCacheAfterImportResult
    {
        if ($clearCacheApp) {
            $this->report($reporter, 'Clearing cache.app pool...');
            $this->cacheApp->clear();
        }

        $currentVersion = $this->datasetVersionService->getVersion();
        $newVersion = $this->datasetVersionService->bumpVersion($datasetVersion);

        $result = new RefreshCacheAfterImportResult(
            previousDatasetVersion: $currentVersion,
            newDatasetVersion: $newVersion,
        );

        if (!$warmup) {
            return $result;
        }

        @ini_set('memory_limit', '768M');

        $paths = $this->buildWarmupPaths();
        if ($paths === []) {
            $this->report($reporter, 'No warmup paths resolved.');
            return $result;
        }

        foreach ($paths as $path) {
            $request = Request::create($path, 'GET', server: [
                'HTTP_ACCEPT' => 'application/ld+json',
                'HTTP_HOST' => 'localhost',
            ]);

            try {
                $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300) {
                    $result->addSuccess($path, $statusCode);
                    $this->report($reporter, sprintf('WARMED %s (%d)', $path, $statusCode));
                    continue;
                }

                $result->addFailure($path, sprintf('status=%d', $statusCode));
                $this->report($reporter, sprintf('FAILED %s (%d)', $path, $statusCode));
            } catch (\Throwable $exception) {
                $result->addFailure($path, $exception->getMessage());
                $this->report($reporter, sprintf('FAILED %s (%s)', $path, $exception->getMessage()));
            }
        }

        return $result;
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

    private function report(?callable $reporter, string $message): void
    {
        if ($reporter !== null) {
            $reporter($message);
        }
    }
}
