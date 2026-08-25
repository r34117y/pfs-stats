# Quickstart: Import Waiting Rankings

## Prerequisites

- Local dependencies are installed.
- The database contains the PFS organization and historical tournament/player
  data needed by the existing import workflow.
- The target tournament is available in the official PFS calendar for the chosen
  year and is not already imported locally.

## Validation Scenario 1: Parser Recognizes Official Waiting List

1. Add or update a parser fixture with a ranking tab containing a main ranking
   followed by a "Poczekalnia na dzień" waiting-list section.
2. Run the focused parser test:

   ```bash
   php bin/phpunit tests/Service/PfsTournamentResultsParserTest.php
   ```

3. Expected outcome: rows before the waiting-list marker are marked as main
   ranking rows, and rows after the marker are marked as waiting-list rows.

## Validation Scenario 2: Import Stores Current and Old Waiting Rankings

1. Choose a PFS tournament at or after "II Wielicki Turniej w Scrabble" that is
   not yet imported locally.
2. Run the import with cache warmup disabled for a focused validation:

   ```bash
   php bin/console app:pfs:tournaments:import --pfsid=<pfs-id> --year=<year> --no-cache-warmup
   ```

3. Verify the imported tournament has ranking rows for all expected ranking
   types:

   ```bash
   php bin/console doctrine:query:sql "SELECT rtype, COUNT(*) FROM ranking WHERE tournament_id = <local-tournament-id> GROUP BY rtype ORDER BY rtype"
   ```

4. Expected outcome: type `f` and `w` rows come from the official ranking tab;
   type `n` and `u` rows are present for tournaments at or after the documented
   old-method divergence point.

## Validation Scenario 3: Earlier Tournament Does Not Require `u`

1. Import or inspect a tournament before "II Wielicki Turniej w Scrabble".
2. Verify ranking rows for the tournament.
3. Expected outcome: current-method ranking rows are stored according to the
   official source, while separate old-method waiting rows are not required
   before the method divergence point.

## Validation Scenario 4: Latest Import Revert Removes Waiting Rankings

1. After a successful import, run:

   ```bash
   php bin/console app:pfs:tournaments:revert-latest-import
   ```

2. Query the reverted tournament id in `ranking`, `tournament_result`,
   `tournament_game`, and `tournament`.
3. Expected outcome: no ranking rows remain for the reverted tournament,
   including types `f`, `w`, `n`, and `u`; other tournament import rows are also
   removed according to the rollback summary.

## Validation Scenario 5: Regression Test Suite

Run focused regression tests first:

```bash
php bin/phpunit tests/Service/PfsTournamentResultsParserTest.php
php bin/phpunit tests/Service/PfsTournamentPayloadFactoryTest.php
php bin/phpunit tests/Service/TournamentRoundImportServiceTest.php
php bin/phpunit tests/Service/TournamentRoundRollbackServiceTest.php
```

Then run the broader relevant suite:

```bash
php bin/phpunit tests/Service tests/Ranking tests/Command
```

Expected outcome: parser, payload, import, old-method waiting-list calculation,
and rollback behavior pass without regressions.
