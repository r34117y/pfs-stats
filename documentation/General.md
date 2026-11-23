## General

The main goal of this project is presenting ranking and statistics related to Polish Scrabble Federation (PFS) and Polish Scrabble clubs. All entities are represented as "organization", where PFS is the main organization.

## PFS games

The main source of truth for PFS is the official website (https://www.pfs.org.pl/). Results are imported from https://www.pfs.org.pl/turniej.php?id={tournamentID} with console command (src/Command/TournamentImport/ImportPfsTournamentCommand.php). Website tournament ids represent the order of tournament registration, not the order of actual tournament dates. The results pages contain several tabs with tournament results, official ranking after tournament, etc. Usually there is one tournament per week (sometimes there are no tournaments and sometimes there are two tournaments). The most common tournament structure is 12 rounds played in 2 days with 7 rounds in the first day and 5 tournaments in the second day, but other structures also occur (one day tournaments with 7 rounds or different rounds distribution within two days or 13 rounds tournaments). Some tournaments have different structure:
- Puchar Polski has groups in the first stage and the dest players compete in a knockount system in the latter stage
- Mistrzosta Polski in the final stage has 16 players: 13 players advance from the eliminations tournaments and 3 players advance based on their results in the GrandPrix series during the whole season (some tournaments have the GrandPrix status)

Most tournaments are yearly events grouped in tournament series (Mistrzostwa Warszawy, Mistrzostwa Poznania, etc.) Usually there is one series per city, but for the biggest cities there can be more.

## PFS Ranking

The official ranking rules (in Polish) can be found in https://www.pfs.org.pl/ranking.php in the "Jak powstaje ranking tab". This page contains also the most recent ranking and the waiting list for players with <30 games played in the last 2 years.

This project maintains also the previous ranging (reffered as "Old ranking"), which had a bit differet rules. Instead of picking the best tournaments from the 2 years window, the last tournaments were picked (with the same rule that the total number of games can't exceed 200). The first tournament to apply the new ranking was "II Wielicki Turniej w Scrabble" - the ranking after this tournament is the first that differs from the old ranking.

Old-method tournament starting ranks are rounded to the nearest integer (halves
round up), with a minimum of 100 for every participant, including waiting-list
and returning players. Ranking-list averages remain unrounded during calculation
and sorting; stored snapshots retain two decimal places.

There are 4 types of PFS ranking types:
- f - the main new ranking (also all the rankings from before the rules changed)
- w - the new ranking waiting list
- n - the main old ranking
- u - the old ranking waiting list

PFS tournament imports store official ranking rows from the tournament ranking
tab. Rows above the waiting-list heading are imported as `f`; rows below
`Poczekalnia na dzień` are imported as `w`. For tournaments from "II Wielicki
Turniej w Scrabble" onward, the import also calculates old-method snapshots:
main old-method rows are stored as `n`, and below-threshold old-method waiting
rows are stored as `u`. Reverting the latest PFS tournament import removes all
ranking rows for the reverted tournament, including `f`, `w`, `n`, and `u`.

Existing imported tournaments can be repaired with the one-time command
`app:pfs:rankings:backfill-waiting-lists`. It scans PFS calendar years, matches
calendar entries to local imported tournaments, imports missing official waiting
lists as `w`, copies those waiting lists as `u` before "II Wielicki Turniej w
Scrabble", and calculates missing old-method waiting lists as `u` from that
tournament onward. Older tournament pages without a ranking tab are skipped.

## Club games

Club tournaments occur usually once a week and club admins upload the results manually (an account is required for that).

## Old Statisctics Website

This project is the succesor of the older statistics website https://scrabble.stats.org.pl/ which is still mantained and can be treated as an additional source of truth for verification.

## Cache

Due to the specific nature of this projects (rare updates, heavy computation related to statistics) caching strategy is essential for this project.

## Architecture

- This app uses Symfony with API Platform
- Regular controllers and twig templates are used to render page Skeletons
- Most data is loaded from API Endpoints using Alpine JS
- Bulma is used as a CSS framework
- Pages must look good on all devices (desktop, mobile)

## Rules

- Try to create and use reusable components, but be sure that you don't break anything while introducing DRY
- The goal is to fully cover the projects with tests - always make sure that tests pass and add or update tests when necessary
- Use the state-of-the-art modern PHP8:
    - make classes final and readonly whenever it is appropriate
    - make properties readonly whenever it is appropriate
    - always type hint everything that supports type-hinting whenever possible
- Type of files you should consider creating:
    - Symfony Controller (used only to render the basic twig template, no data is fetched within the controller)
    - Twig template (contains the HTML skeleton of the page and the relevant AlpineJS code)
    - ApiResource (represents a single endpoint)
    - Provider / Processor - according to ApiPlatform best practices
    - Console Command - use classes provided by Symfony
    - Service - the executor of business logic (should be usable by API Platform state management classes and console commands)
- You can use `doctrine:query:sql` for testing created SQL
- You can execute commands in docker containers to verify that your code works as intended
    - Containers for this project are prefixed with `scrabble-stats-api-`, for example `scrabble-stats-api-php-1`
    - Credentials can usually be found in `.env` or `.env.local`

## Validated single-tournament PFS imports

`app:pfs:tournaments:import --pfsid=<PFS URL id> --year=<year>` imports exactly one
completed tournament. PFS URL IDs are registration IDs, not database IDs or
chronological ordering. Use the dated sequence in
`specs/002-fix-pfs-import/quickstart.md`; for simultaneous tournaments preserve the
existing service order, so the second old-method snapshot includes the first.

The importer validates classification, player HH blocks, round results and both
official ranking lists. Significant unrecognized source rows block import with a
section/line diagnostic. Complete `tournament_html` snapshots are reused;
announcements are refreshed. Source metadata includes PFS ID, URL and SHA-256;
legacy snapshots have a verification timestamp and unknown original fetch time.
HTML bytes are retained. HH scalps rounded per game can differ from the published
classification total; both source values are retained and the discrepancy is
reported. Legacy 400:0 walkovers may still have HH scalps: their result is retained
but they do not contribute ranking games. New non-ranking game rows have null
`ranko`; existing historical representation is preserved.

The read-only `bin/verify-pfs-import.php` has `preflight` and `verify` modes. It
creates a source/history manifest and compares all participants, classification,
logical games and f/w/n/u, using an independent old-method reference calculation.
A normal expected append does not invalidate the manifest. Historical corrections,
source or identity mapping changes require preflight again. Baseline fingerprints
are bound to the original tournament set, so explicitly planned historical
additions can be verified without treating them as unexpected mutations.

Confirmed mappings live in `config/pfs_player_aliases.yaml`; context mappings can
use source city to disambiguate a confirmed identity. Similar names alone do not
authorize merging profiles. Ranking-only debutants require a verified participant
source; evidence from a second same-day tournament permits creating a profile and
official ranking entry, without importing its games ahead of that tournament.
Unresolved identities block the operation and are listed in the preflight report.

The CLI returns `unchanged` for a fully matching import, with no database/audit
writes or cache publication. Partial/conflicting imports require explicit repair.
Tournament data, new profiles, all ranking types and audit are transactional.
Audit excludes authorization tokens. Organization locks serialize imports,
rollback and old-ranking repairs.

Dataset version publication happens synchronously after commit.
`framework.cache.prefix_seed` is fixed per environment so CLI and HTTP share the
same version key even when their debug flags differ. Rebuild the Symfony container
for the selected environment/debug mode when deploying constructor/config changes.
`PFS_IMPORT_MANIFEST_PATH` can select a manifest belonging to a separate acceptance
database; each copy must also use its own Redis database.
`--no-cache-warmup` skips only optional warming. A publication failure reports
`committed-cache-error`: repair the cache using
`app:cache:refresh-after-import --org=<organization id>`, then resume; do not
reimport or roll back valid tournament data to repair cache. Delayed warming uses
`--reuse-version` and cannot restore an obsolete version.

If preflight establishes missing older ranking events, the explicit
`--include-history-repairs` option adds those sources to a confirmed repair plan;
it still performs no database imports. Preserve legacy composite events: MP
eliminations may already be rounds 1–15 of a combined 30-round championship, and a
PFS source can map to multiple historical local events. After necessary repairs,
`app:ranking:backfill-old-method-snapshots --changed-only` updates only divergent
n/u snapshots and leaves equal historical rows untouched. Regenerate preflight
before further verification after this intentional history correction.

For MP championships at or after the old-method split (May 2023), eliminations
and finals must be separate PFS events. Run
`php -d memory_limit=1G bin/split-pfs-championship-phases.php --mode=plan`, then
`--mode=apply --dry-run`, and finally `--mode=apply` after taking a database backup.
The tool discovers combined MP records from that boundary, validates the official
PFS calendar and both result pages, preserves final tournament IDs and every
existing game ID/GCG/photo, and renumbers final games from 16–30 to 1–15.
It restores separate classifications and official f/w lists, adds omitted bye
records, and rebuilds n/u in the same transaction. Unrelated data and annotations
are fingerprinted; all repaired phases are compared with PFS and every old-method
snapshot with an independent calculator before commit. Older championships are
excluded. The source hashes, original metadata, repair audit and reports are kept.
Use `--mode=verify` to repeat validation without changes. Reapplying is a no-op;
cache version publication runs only after a committed repair.

Use an isolated PostgreSQL database ending in `_test` via
`PFS_IMPORT_TEST_DATABASE_URL` for writing integration tests. For API tests set
`APP_ENV=test` explicitly in Compose and use isolated Redis database 14; acceptance
runs use a restored database and separate Redis database 13. Production deployment
continues to use `bin/deploy-prod.sh`; these tools do not deploy the application.
