# GET ApiResource Tests Plan

Rules for this plan:

- Mark a task complete only after the named PHPUnit test or suite passes.
- Use the dev database as the read source. Do not create fixtures, truncate tables, run migrations, import dumps, or write application data.
- Exercise not-cached behavior. Clear or isolate `app.dataset_cache` / `cache.app` before every endpoint request so each assertion observes provider/service execution instead of a warmed response.
- Prefer endpoint-level tests through the Symfony/API Platform HTTP layer with the API's supported JSON media type, currently `Accept: application/ld+json`.

## Test Infrastructure

- [x] Add `tests/Api/ApiEndpointTestCase.php` with helpers to boot the HTTP client against the dev database, request `/api...` URLs as JSON, decode JSON responses, and clear `cache.app` before each request. Passes when a tiny self-test endpoint request proves the cache-clear helper works without changing the database.
- [x] Add a read-only dev-data lookup helper for representative IDs and slugs: organization ID, player slug, tournament ID, tournament/player pair, annotated game ID, and an organization-admin user if present. Passes when helper tests can resolve values or skip with a clear PHPUnit skipped reason without writing to the database.
- [x] Add an endpoint inventory test that discovers every `new Get(...)` operation under `src/ApiResource`, maps it to its `/api` route, and fails if a GET ApiResource endpoint has no explicit test coverage entry. Passes when the inventory test matches the current ApiResource GET count.
- [x] Add shared assertions for cache headers on successful public GETs: `Cache-Control` is public with `s-maxage=86400` and `stale-while-revalidate=3600`; profile endpoints remain private/no-store. Passes when used by at least one public and one private endpoint test.

## Public List Endpoints

- [x] Test `GET /api/ranking` from a cold cache. Assert `200`, JSON object shape, non-empty `rows` when dev data exists, and public cache headers. Passes with `php bin/phpunit --filter RankingEndpointTest`.
- [ ] Test `GET /api/old-rank` from a cold cache. Assert `200`, ranking JSON shape, and public cache headers. Passes with `php bin/phpunit --filter OldRankingEndpointTest`.
- [ ] Test `GET /api/players` from a cold cache. Assert `200`, list JSON shape, player row identifiers/slugs/names, and public cache headers. Passes with `php bin/phpunit --filter PlayersListEndpointTest`.
- [ ] Test `GET /api/tournaments` from a cold cache. Assert `200`, tournament list JSON shape, stable required fields, and public cache headers. Passes with `php bin/phpunit --filter TournamentsListEndpointTest`.
- [ ] Test `GET /api/organizations` from a cold cache. Assert `200`, organization list JSON shape, and public cache headers. Passes with `php bin/phpunit --filter OrganizationsListEndpointTest`.
- [ ] Test `GET /api/clubs` from a cold cache. Assert `200`, club list JSON shape, and public cache headers. Passes with `php bin/phpunit --filter ClubsListEndpointTest`.
- [ ] Test `GET /api/annotated-games` from a cold cache. Assert `200`, pagination/list shape, row fields when data exists, and public cache headers. Passes with `php bin/phpunit --filter AnnotatedGamesEndpointTest`.

## Player Endpoints

- [ ] Test `GET /api/players/{slug}` from a cold cache using a dev-data slug. Assert `200`, profile shape, tournament summary fields, and public cache headers. Passes with `php bin/phpunit --filter PlayerProfileEndpointTest`.
- [ ] Test `GET /api/players/{slug}/tournaments` from a cold cache. Assert `200`, tournament row shape, and public cache headers. Passes with `php bin/phpunit --filter PlayerTournamentsEndpointTest`.
- [ ] Test `GET /api/players/{slug}/game-balance` from a cold cache. Assert `200`, balance row shape, and public cache headers. Passes with `php bin/phpunit --filter PlayerGameBalanceEndpointTest`.
- [ ] Test `GET /api/players/{slug}/rank-history` from a cold cache. Assert `200`, point list shape, chronological fields, and public cache headers. Passes with `php bin/phpunit --filter PlayerRankHistoryEndpointTest`.
- [ ] Test `GET /api/players/{slug}/rank-history/milestones` from a cold cache. Assert `200`, milestone list shape, and public cache headers. Passes with `php bin/phpunit --filter PlayerRankMilestonesEndpointTest`.
- [ ] Test all player record endpoints for one slug from a cold cache: `most-points`, `least-points`, `points-highest-sum`, `points-lowest-sum`, `opponent-most-points`, `opponent-least-points`, `highest-win`, `highest-lose`, `highest-draw`, `lost-with-most-points`, `won-with-least-points`, `won-with-most-points-by-opponent`, `lost-with-least-points-by-opponent`, `win-streak`, `lose-streak`, `streak-by-points`, `streak-by-sum`, `win-streak-by-player`, `lose-streak-by-player`. Assert `200`, common table/row shape, and public cache headers for each. Passes with `php bin/phpunit --filter PlayerRecordsEndpointTest`.

## Tournament And Game Endpoints

- [ ] Test `GET /api/tournaments/{id}/details` from a cold cache using a dev-data tournament ID. Assert `200`, details shape, and public cache headers. Passes with `php bin/phpunit --filter TournamentDetailsEndpointTest`.
- [ ] Test `GET /api/tournaments/{id}/results` from a cold cache. Assert `200`, result rows shape, and public cache headers. Passes with `php bin/phpunit --filter TournamentResultsEndpointTest`.
- [ ] Test `GET /api/tournaments/{tournamentId}/players/{playerSlug}/summary` from a cold cache. Assert `200`, summary stats/games shape, and public cache headers. Passes with `php bin/phpunit --filter PlayerTournamentSummaryEndpointTest`.
- [ ] Test `GET /api/games/{id}` from a cold cache using a dev-data annotated game ID. Assert `200`, game detail shape, and public cache headers. Passes with `php bin/phpunit --filter AnnotatedGameDetailsEndpointTest`.

## Stats Endpoints

- [ ] Test core aggregate stats from a cold cache: `/api/stats/all-time-summary`, `/api/stats/all-times-results`, `/api/stats/yearly-all-times-results`, `/api/stats/yearly-ranking-summary`, `/api/stats/ranking-leaders`. Assert `200`, expected top-level fields, row shapes, and public cache headers. Passes with `php bin/phpunit --filter StatsAggregateEndpointsTest`.
- [ ] Test count/rank stats from a cold cache: `/api/stats/games`, `/api/stats/games-won`, `/api/stats/tournaments`, `/api/stats/rank-all-games`, `/api/stats/highest-rank`, `/api/stats/highest-rank-position`, `/api/stats/highest-tournament-rank-record`, `/api/stats/lowest-tournament-rank-record`. Assert `200`, row shapes, numeric fields, and public cache headers. Passes with `php bin/phpunit --filter StatsCountRankEndpointsTest`.
- [ ] Test points average stats from a cold cache: `/api/stats/avg-points-per-game`, `/api/stats/avg-opponents-points`, `/api/stats/avg-points-sum`, `/api/stats/avg-points-difference`, `/api/stats/highest-avg-points-sum`, `/api/stats/lowest-avg-points-sum`, `/api/stats/highest-avg-points-diff`, `/api/stats/lowest-avg-points-diff`, `/api/stats/highest-avg-small-points`, `/api/stats/lowest-avg-small-points`. Assert `200`, row shapes, numeric fields, and public cache headers. Passes with `php bin/phpunit --filter StatsAverageEndpointsTest`.
- [ ] Test points record stats from a cold cache: `/api/stats/most-small-points`, `/api/stats/least-small-points`, `/api/stats/highest-points-sum`, `/api/stats/lowest-points-sum`, `/api/stats/most-points-and-loss`, `/api/stats/least-points-and-win`, `/api/stats/most-opponent-points-and-win`, `/api/stats/least-opponent-points-and-loss`, `/api/stats/games-over-400`. Assert `200`, row shapes, numeric fields, and public cache headers. Passes with `php bin/phpunit --filter StatsPointsRecordEndpointsTest`.
- [ ] Test streak/opponent/draw/victory stats from a cold cache: `/api/stats/longest-win-streaks`, `/api/stats/longest-loss-streaks`, `/api/stats/longest-win-streak-vs-player`, `/api/stats/longest-streak-min-350`, `/api/stats/longest-streak-min-400`, `/api/stats/longest-streak-sum-min-750`, `/api/stats/longest-streak-sum-min-800`, `/api/stats/different-opponents`, `/api/stats/highest-victory`, `/api/stats/highest-draw`. Assert `200`, row shapes, numeric fields, and public cache headers. Passes with `php bin/phpunit --filter StatsStreakEndpointsTest`.

## Protected GET Endpoints

- [ ] Test unauthenticated access for protected GET endpoints: `/api/user/profile/data`, `/api/user/players/manage/data`, `/api/user/tournament-results/add/data`. Assert the configured unauthenticated response without mutating data. Passes with `php bin/phpunit --filter ProtectedGetUnauthenticatedEndpointTest`.
- [ ] Test authenticated `GET /api/user/profile/data` with an existing dev user from a cold cache or non-cached provider path. Assert `200`, profile JSON shape, and private/no-store cache headers. Passes with `php bin/phpunit --filter UserProfileEndpointTest`.
- [ ] Test authenticated `GET /api/user/players/manage/data` with an existing organization-admin dev user. Assert `200`, admin context shape, organizations shape, and private/no-store cache headers if covered by `/api/user/profile/` rules or the actual configured headers otherwise. Passes with `php bin/phpunit --filter ManagePlayersDataEndpointTest`.
- [ ] Test authenticated `GET /api/user/tournament-results/add/data` with an existing organization-admin dev user. Assert `200`, admin context shape, recent imports shape, and private/no-store cache headers if covered by `/api/user/profile/` rules or the actual configured headers otherwise. Passes with `php bin/phpunit --filter AddTournamentResultsDataEndpointTest`.

## Error And Coverage Guards

- [ ] Add invalid identifier tests for representative route-variable endpoints: unknown player slug, unknown tournament ID, unknown game ID, and invalid tournament/player summary pair. Assert `404` or the current documented API error shape. Passes with `php bin/phpunit --filter ApiGetNotFoundEndpointTest`.
- [ ] Add a cache regression test that primes one cacheable endpoint, clears the cache, then requests it again and verifies the response still comes from the provider path and remains valid. Passes with `php bin/phpunit --filter ApiColdCacheRegressionTest`.
- [ ] Run the full API endpoint suite against the dev database with cache clearing enabled. Passes with `php bin/phpunit tests/Api`.
