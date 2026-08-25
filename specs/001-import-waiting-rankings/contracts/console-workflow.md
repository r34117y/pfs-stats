# Console Workflow Contract: Import Waiting Rankings

## Existing Import Command

Command: `app:pfs:tournaments:import`

### Inputs

- `--pfsid`: Required positive official PFS tournament id.
- `--year`: Positive calendar year. Defaults to the current year.
- `--no-cache-warmup`: Optional flag to skip cache warmup launch after import.

### Successful Outcome

For the requested imported tournament:

- Tournament, players, games, tournament results, and import audit are created.
- Official main ranking rows are stored as type `f`.
- Official current waiting-list rows from the PFS ranking tab are stored as type
  `w`.
- Old-method main ranking rows are stored as type `n` when the tournament is at
  or after the documented divergence tournament.
- Old-method waiting-list rows are stored as type `u` when the tournament is at
  or after the documented divergence tournament.
- Cache warmup is launched unless `--no-cache-warmup` is present.
- Console output includes the imported tournament id, PFS id, date, city, player
  count, game count, and ranking counts for `f`, `w`, `n`, and `u`.

### Failure Outcome

- Invalid `--pfsid` or `--year` returns invalid command status.
- Missing calendar tournament, unreadable source page, malformed required source
  data, duplicate tournament, or unresolved required ranking player returns
  failure status.
- Failed imports are atomic: no partial tournament, game, result, ranking, or
  audit data remains from the failed transaction.

## Existing Latest-Import Revert Command

Command: `app:pfs:tournaments:revert-latest-import`

### Inputs

- No user-facing options are required for this feature.

### Successful Outcome

For the latest audited PFS import:

- All ranking rows for the imported tournament are deleted, including `f`, `w`,
  `n`, and `u`.
- Tournament result rows, tournament game rows, tournament row, audit row, and
  safe-to-delete created player rows are reverted.
- Console output reports deleted ranking rows and other existing rollback
  summary metrics.
- Cache warmup is launched after rollback.

### Failure Outcome

- Missing import audit returns failure status.
- Unexpected inability to delete the audited tournament returns failure status
  and rolls back the revert transaction.

## Idempotency and Duplicate Rules

- A duplicate tournament import remains rejected before any ranking rows are
  added.
- Ranking rows created inside a successful import are protected by the existing
  uniqueness rule: one row per organization, ranking type, player, and
  tournament.
- Reverting and re-importing the same tournament may recreate the same four
  ranking types for that tournament.
