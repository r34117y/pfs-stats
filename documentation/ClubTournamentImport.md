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

The tournament directory is authoritative for date and name, including its `BlubryXXX` number. The console derives it from the HH path; in the browser select the tournament directory or supply its name in the “Katalog turnieju” field (`sourceDirectory` is required for Blubry multipart requests). Raw HH/final files are still cross-validated together before canonical directory metadata is applied. `2505.2026` is accepted as the legacy spelling of `25052026`.

The canonical corpus forms a chronological consecutive sequence 528–565. In particular, directory names resolve the conflicting file headings as Regosol 535, Hipoksja 536, and Klymenia 540. Directory metadata is included in the import audit.

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

## Ranking values and consecutive comparisons

Blubry imports fetch the post-tournament list from `https://scrabble.stats.org.pl/blubry/karank.php?t=YYYYMMDD0&r=f`. Ranking values, positions, player identities, page completeness, and the requested source date are validated before writing. Source HTML, URL, SHA-256, and resolved rows are retained.

A source game count of zero means that the page does not supply the count. It does **not** invalidate the supplied ranking values and positions. Complete lists are stored in `ranking` as `rtype=f`, with audit type 9004. Lists with missing game counts are stored separately in `text_resource` type 9006, with JSON `games: null`. They are available for ranking comparisons and navigation; the original unknown counts remain null in the source audit. For Blubry, the API and browser calculate game counts from imported HH game records using the rules below. When a complete list later becomes available, it takes precedence.

Tournament results and either kind of ranking data are stored in one transaction. Unknown identities or conflicting existing values abort the transaction. If the source cannot be fetched at all, results may be saved with an explicit warning. New player profiles receive slugs so that the ranking API can display them.

The ranking service compares with the **immediately preceding tournament** in chronological `(dt,id)` order. It does not skip an unchanged ranking and does not accumulate changes across a missing snapshot. If the preceding values are absent, rank and position changes remain null. The API exposes `comparisonTournamentId`, `comparisonTournamentName`, `comparisonAvailable`, and `gameCountsAvailable`; the page names the actual comparison tournament.

For existing tournaments, validate and then import the missing values:

```bash
php bin/console app:clubs:rankings:import 4 \
  --from=2026-02-24 --to=2026-11-17 --dry-run
php bin/console app:clubs:rankings:import 4 \
  --from=2026-02-24 --to=2026-11-17
```

All validated snapshots are stored in one transaction. Identical snapshots are a no-op, including cache publication. `--skip-unpublished` explicitly permits skipping unavailable source pages; it is not needed merely because a source omits game counts. The upper bound excludes Halacha on 2026-11-24.

The directory date for Klymenia remains 2026-05-25. The old source lists that event under 2026-05-26, so the source URL lookup uses that explicit alias without changing the authoritative directory date or number 540.

The corpus has six lists with complete game counts (140 rows) and 32 lists with ranking values and unknown counts (726 rows). Sanidyn (17 November) compares to Amsonia (10 November): Jakub Błażejewski changes from 161.00 to 163.70, or +2.70; Adam Grzelec remains at 156.03, or zero change. Their previous September-based changes were misleading.

`tests/Browser/club-ranking-comparison.cjs` verifies the actual home page, displayed changes, previous/next navigation to Amsonia, calculated game counts, and absence of the removed messages. Integration tests cover missing predecessors, unchanged consecutive snapshots, partial source data, and authoritative directory metadata in console and multipart imports.

### Blubry ranking game counts

Blubry ranking eligibility requires at least **10 counted games**. Players with 0–9 games are omitted from both current and historical rankings. Remaining positions are numbered consecutively, and position changes compare the eligible lists for both tournaments. Eligibility filtering does not modify imported source snapshots.

The displayed count uses the six calendar months ending on the selected tournament, including the boundary date (clamped to the last day of the month). Following the PFS selection rule, whole tournaments are ordered by achieved tournament ranking, highest first; the lowest-performing tournaments are discarded until the total is at most 60. No tournament is split or skipped to fill unused places. Ties use date and tournament ID, newest first. Later tournaments, including those later on the same date, are excluded.

Count actual player-perspective HH games with an opponent and ranking contribution. Byes and non-ranking 400:0 / 0:400 forfeits do not count. Confirmed Blubry aliases are resolved for this calculation. The source ranking values, positions and audit records remain intact. Other organizations retain their existing counting rules.

Daniel Ziarnik illustrates why source-list membership cannot establish eligibility: the imported Amsonia ranking (10 November 2026) includes him at 112.14. His last recorded club appearance is Itajara (3 March 2026, five games), outside the 10 May–10 November window. His computed count is therefore zero and he is omitted under the 10-game rule. Previously the count was recalculated but the source membership was retained, incorrectly displaying this stale entry.
