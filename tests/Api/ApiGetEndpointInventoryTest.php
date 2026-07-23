<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ApiGetEndpointInventoryTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const array COVERED_GET_ROUTES = [
        '/api/annotated-games',
        '/api/clubs',
        '/api/games/{id}',
        '/api/old-rank',
        '/api/organizations',
        '/api/players',
        '/api/players/{slug}',
        '/api/players/{slug}/game-balance',
        '/api/players/{slug}/rank-history',
        '/api/players/{slug}/rank-history/milestones',
        '/api/players/{slug}/records/highest-draw',
        '/api/players/{slug}/records/highest-lose',
        '/api/players/{slug}/records/highest-win',
        '/api/players/{slug}/records/least-points',
        '/api/players/{slug}/records/lose-streak',
        '/api/players/{slug}/records/lose-streak-by-player',
        '/api/players/{slug}/records/lost-with-least-points-by-opponent',
        '/api/players/{slug}/records/lost-with-most-points',
        '/api/players/{slug}/records/most-points',
        '/api/players/{slug}/records/opponent-least-points',
        '/api/players/{slug}/records/opponent-most-points',
        '/api/players/{slug}/records/points-highest-sum',
        '/api/players/{slug}/records/points-lowest-sum',
        '/api/players/{slug}/records/streak-by-points',
        '/api/players/{slug}/records/streak-by-sum',
        '/api/players/{slug}/records/win-streak',
        '/api/players/{slug}/records/win-streak-by-player',
        '/api/players/{slug}/records/won-with-least-points',
        '/api/players/{slug}/records/won-with-most-points-by-opponent',
        '/api/players/{slug}/tournaments',
        '/api/ranking',
        '/api/stats/all-time-summary',
        '/api/stats/all-times-results',
        '/api/stats/avg-opponents-points',
        '/api/stats/avg-points-difference',
        '/api/stats/avg-points-per-game',
        '/api/stats/avg-points-sum',
        '/api/stats/different-opponents',
        '/api/stats/games',
        '/api/stats/games-over-400',
        '/api/stats/games-won',
        '/api/stats/highest-avg-points-diff',
        '/api/stats/highest-avg-points-sum',
        '/api/stats/highest-avg-small-points',
        '/api/stats/highest-draw',
        '/api/stats/highest-points-sum',
        '/api/stats/highest-rank',
        '/api/stats/highest-rank-position',
        '/api/stats/highest-tournament-rank-record',
        '/api/stats/highest-victory',
        '/api/stats/least-opponent-points-and-loss',
        '/api/stats/least-points-and-win',
        '/api/stats/least-small-points',
        '/api/stats/longest-loss-streaks',
        '/api/stats/longest-streak-min-350',
        '/api/stats/longest-streak-min-400',
        '/api/stats/longest-streak-sum-min-750',
        '/api/stats/longest-streak-sum-min-800',
        '/api/stats/longest-win-streak-vs-player',
        '/api/stats/longest-win-streaks',
        '/api/stats/lowest-avg-points-diff',
        '/api/stats/lowest-avg-points-sum',
        '/api/stats/lowest-avg-small-points',
        '/api/stats/lowest-points-sum',
        '/api/stats/lowest-tournament-rank-record',
        '/api/stats/most-opponent-points-and-win',
        '/api/stats/most-points-and-loss',
        '/api/stats/most-small-points',
        '/api/stats/rank-all-games',
        '/api/stats/ranking-leaders',
        '/api/stats/tournaments',
        '/api/stats/yearly-all-times-results',
        '/api/stats/yearly-ranking-summary',
        '/api/tournaments',
        '/api/tournaments/{id}/details',
        '/api/tournaments/{id}/results',
        '/api/tournaments/{tournamentId}/players/{playerSlug}/summary',
        '/api/user/players/manage/data',
        '/api/user/profile/data',
        '/api/user/tournament-results/add/data',
    ];

    public function testEveryApiResourceGetEndpointHasCoverageEntry(): void
    {
        $discoveredRoutes = $this->discoverApiResourceGetRoutes();
        $coveredRoutes = self::COVERED_GET_ROUTES;
        sort($coveredRoutes);

        self::assertSame(
            $discoveredRoutes,
            $coveredRoutes,
            'Update COVERED_GET_ROUTES when adding or removing GET ApiResource endpoints.',
        );
        self::assertCount(80, $coveredRoutes);
        self::assertSame($coveredRoutes, array_values(array_unique($coveredRoutes)), 'Duplicate covered GET route entry.');
    }

    /**
     * @return list<string>
     */
    private function discoverApiResourceGetRoutes(): array
    {
        $apiResourceDir = dirname(__DIR__, 2) . '/src/ApiResource';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiResourceDir));
        $routes = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);

            $offset = 0;
            while (($operationPosition = strpos($source, 'new Get(', $offset)) !== false) {
                $uriTemplatePosition = strpos($source, 'uriTemplate:', $operationPosition);
                self::assertNotFalse($uriTemplatePosition, sprintf('Missing uriTemplate for GET operation in %s.', $file->getPathname()));

                $quotePosition = strpos($source, "'", $uriTemplatePosition);
                $endQuotePosition = $quotePosition === false ? false : strpos($source, "'", $quotePosition + 1);
                self::assertNotFalse($quotePosition, sprintf('Could not parse uriTemplate in %s.', $file->getPathname()));
                self::assertNotFalse($endQuotePosition, sprintf('Could not parse uriTemplate in %s.', $file->getPathname()));

                $routes[] = '/api' . substr($source, $quotePosition + 1, $endQuotePosition - $quotePosition - 1);
                $offset = $operationPosition + strlen('new Get(');
            }
        }

        sort($routes);

        return $routes;
    }
}
