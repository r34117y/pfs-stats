# Club tournament results import

The import accepts an HH file and its final standings file. Both Windows-1250 and UTF-8 are supported. The organization must already exist; BLUBRY has local id `4`.

```bash
docker compose exec -T php php bin/console app:clubs:tournaments:import \
  Blubry_turnieje/17032026_Blubry531_KuczbajHH.txt 4
```

The console automatically reads the adjacent file with `HH` removed from its filename. Use `--standings=/path/to/results.txt` if the final standings file has another name.

In `/user/tournament-results/add`, select the organization and **both files together** in the file picker. The order of the selected files does not matter. The preview does not write to the database; confirming imports one tournament. The API uses multipart fields `organizationId`, `results` (HH), and `standings` (final classification). An organization administrator is required. Importing the same organization/date/name again returns a conflict without inserting rows.

## Source limitations and validation

HH uses `PAUZA` for both an awarded pause and an absence. A pause earns 350 small points and 0.5 large points; an absence earns neither. HH alone cannot distinguish them, so files containing `PAUZA` require the final classification. The parser validates the tournament heading, player names/count, score totals, and large points against that classification before any database writes. Invalid dates, unrecognized game rows, inconsistent files, and missing opponents fail the import.

If a player has several pauses and only some earned points, the files identify their number but not their rounds. Their points and game totals are retained in `tournament_result`. Their counts are also recorded as `byesWithoutKnownRound` in the import audit (`text_resource`, type 9002). No game with a guessed round is inserted. Known-round awarded pauses have one game row; a played game has two rows, one from each player's perspective. HH does not identify the starting player, so `host` remains null. A pause has no opponent ranking.

Source headings are retained, including existing discrepancies with directory names (for example, Klymenia is named `Blubry549_Klymenia` inside both source files).

Players already belonging to the target club are matched first. Other existing global profiles are reused and linked to the club; genuinely new names create new profiles. Ambiguous matches fail instead of choosing an arbitrary profile. Players, organization links, tournament, games, standings, and audit are written in one transaction.

A successful console or HTTP import publishes a new dataset cache version. If cache publication fails after commit, the response explicitly says the tournament was saved. Repair the cache with `app:cache:refresh-after-import`; do not attempt to insert the tournament again.

## Automated checks

Create a separate database ending in `_test` with the application's PostgreSQL schema. The integration harness refuses the normal application database, and truncates tables only in the explicitly supplied test database.

```bash
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=0 \
  -e PFS_IMPORT_TEST_DATABASE_URL=postgresql://app:app@postgres:5432/blubry_import_test \
  php php vendor/bin/phpunit --filter ClubTournament
```

The tests cover real source files, Polish characters, padded scores (`390: 89`), the dotted date `2505.2026`, classification consistency, ambiguous pauses, console success/failure, multipart responses, permissions, player reuse, transaction rollback, duplicates, and cache publication including failure after commit.

The real Chrome test is `tests/Browser/club-tournament-import.cjs`. It needs Playwright, a Chrome executable, an administrator account, and an unimported pair of files. It performs a real import: use a disposable database or deliberately selected local tournament. Credentials are read from a local JSON file with `email` and `password`; keep that file out of git.

```bash
npm install --prefix /tmp/club-browser playwright
PLAYWRIGHT_MODULE=/tmp/club-browser/node_modules/playwright \
CLUB_IMPORT_AUTH_FILE=/tmp/club-browser/auth.json \
CLUB_IMPORT_HH=/absolute/path/resultsHH.txt \
CLUB_IMPORT_STANDINGS=/absolute/path/results.txt \
CLUB_IMPORT_ORGANIZATION_ID=4 \
node tests/Browser/club-tournament-import.cjs
```

The browser test covers login, missing final classification, preview, cancellation, confirmation, recent imports, duplicate prevention, missing files, organization validation, permissions, tournament details, and invalidation of a previously cached tournament list.

## Local Blubry import, 2026-11-27

All 38 source tournaments preceding `24112026_Blubry566_GP3_Halacha` are present in local organization 4: 35 newly imported (34 through the console and Sanidyn through Chrome), two earlier imports corrected, and one earlier import already correct. Final verification matched the standings and every known-round game against all 38 pairs of source files: 420 tournament results and 1,905 game rows. The excluded tournament has no database entry.

The previous data for the corrected standings and starting-player fields is backed up locally under `var/club-import/`. Temporary browser tournaments and the temporary administrator account were removed after verification.

## Published ranking snapshots

The home page reads saved `ranking` rows (`rtype=f`); importing tournament results alone does not advance that page. Blubry imports now fetch the published post-tournament ranking from `https://scrabble.stats.org.pl/blubry/karank.php?t=YYYYMMDD0&r=f` and save it in the same transaction as the results. Its source HTML, SHA-256, URL, and resolved rows are retained in audit resources of type 9004. Player matching requires exactly one existing organization profile for each published name; unresolved identities or conflicting existing snapshots abort the transaction.

The source also serves placeholder lists with starting ranks and **zero games**. These must not be used as post-tournament rankings. If the source is unavailable or only such a placeholder is published, tournament results can be saved, but both the console and browser explicitly report that the home page ranking remains at the last available snapshot. A malformed or incorrectly dated ranking is rejected. New player profiles receive slugs so that a newly ranked player can be displayed by the ranking API.

For existing tournaments, fetch and validate snapshots first:

```bash
php bin/console app:clubs:rankings:import 4 \
  --from=2026-02-24 --to=2026-11-17 --skip-unpublished --dry-run
```

Then omit `--dry-run` to write the complete snapshots in one transaction. The explicit `--skip-unpublished` option reports and skips unavailable/placeholder lists; without it, any missing published list aborts the operation. Repeating the import of identical snapshots does not write rows or publish another cache version. The upper date bound deliberately excludes Halacha on 2026-11-24.

The original Klymenia files have an erroneous date, 2026-05-25, whereas the published event is 2026-05-26. The ranking source lookup contains this explicit date mapping; it does not accept an arbitrary date returned by a source page.

On 2026-11-27 the complete available snapshots in this range were 17 March, 28 April, 9 June, 28 July, 22 September, and 17 November: 140 main-ranking rows in total. The other source pages contained zero-game placeholders and were not promoted to rankings. The 17 November snapshot has 24 players and is headed by Jakub Błażejewski (163.70). Regression coverage includes the actual home-page ranking service, API endpoint, and browser rendering, in addition to tournament-list checks.
