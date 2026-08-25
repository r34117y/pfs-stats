# Data Model: Import Waiting Rankings

## Tournament Calendar Entry

Represents an official PFS calendar tournament used by the import command to
resolve metadata for a requested PFS tournament id.

**Fields**:

- `urlId`: Official PFS tournament result-page identifier.
- `name`: Calendar tournament name.
- `location`: Calendar location used as imported tournament city.
- `startDate`: Tournament start date.
- `endDate`: Tournament end date.

**Relationships**:

- Resolves to one imported tournament during a successful single-tournament
  import.

**Validation Rules**:

- Requested `urlId` must exist in the selected calendar year.
- Calendar metadata must be available before tournament import starts.

## Imported Tournament

Represents the local tournament created by the existing PFS import workflow.

**Fields**:

- `organizationId`: PFS organization id.
- `tournamentId`: Local tournament id.
- `pfsUrlId`: Official PFS source id where available.
- `name`: Full imported tournament name.
- `date`: Tournament date code.
- `city`: Tournament location.

**Relationships**:

- Has tournament result rows, tournament game rows, ranking entries, and one
  import audit row.

**Validation Rules**:

- Duplicate tournament import remains rejected before any waiting-list rows are
  inserted.
- Import audit must identify the tournament so rollback can revert all imported
  rows.

## Ranking Entry

Represents one row in a tournament ranking snapshot.

**Fields**:

- `organizationId`: PFS organization id.
- `rankingType`: One of `f`, `w`, `n`, or `u`.
- `playerId`: Local player id.
- `tournamentId`: Local tournament id for the ranking snapshot.
- `position`: Position within that ranking type.
- `rank`: Ranking value rounded for storage.
- `games`: Number of games counted by that ranking type.

**Relationships**:

- Belongs to one imported tournament and one local player.
- Current-method rows (`f`, `w`) come from the official PFS ranking tab.
- Old-method rows (`n`, `u`) come from the old-method ranking simulation.

**Validation Rules**:

- A player can have at most one row per organization, ranking type, and
  tournament.
- Import must not create duplicate rows on retry after a failed transaction.
- Rollback of the imported tournament must remove all ranking types for that
  tournament.

## Official Waiting List

Represents rows listed below the main ranking on the PFS tournament ranking tab.

**Fields**:

- `position`: Official waiting-list position.
- `playerName`: Official player name.
- `city`: Official city value.
- `rank`: Official current-method ranking value.
- `scalp`: Official accumulated scalp value.
- `games`: Official current-method counted games.

**Relationships**:

- Parsed into current-method `w` ranking entries.
- Requires matching to local player records.

**Validation Rules**:

- Rows after the "Poczekalnia na dzień" marker are waiting-list rows.
- Unmatched waiting-list players must fail or report clearly according to the
  existing import error pattern; they must not be silently ignored.

## Old-Method Ranking Snapshot

Represents calculated ranking state after a tournament using the prior PFS
ranking rules.

**Fields**:

- `referenceTournamentId`: Tournament id for the snapshot.
- `referenceDate`: Tournament date.
- `windowStartDate`: Start of the two-year ranking window.
- `mainRows`: Players meeting the main-list threshold, stored as type `n`.
- `waitingRows`: Players below the main-list threshold but eligible for the
  old-method waiting list, stored as type `u`.

**Relationships**:

- Calculated from tournament results, tournament games, player history, and the
  pre-divergence ranking state.

**Validation Rules**:

- Snapshots are produced only from "II Wielicki Turniej w Scrabble" onward.
- Old-method selection uses latest tournaments in the two-year window and stops
  before exceeding the maximum counted-game limit.
- Main and waiting rows must be sorted by the same old-method ranking ordering:
  rank descending, games descending, player sort name ascending.

## Import Audit

Represents the audit marker used to revert the latest tournament import.

**Fields**:

- `organizationId`: PFS organization id.
- `tournamentId`: Local tournament id.
- `createdPlayerIds`: Players created during import.
- `importedAt`: Import timestamp.

**Relationships**:

- Points to one imported tournament.
- Used by latest-import rollback to delete tournament-scoped data.

**Validation Rules**:

- The audit record must be written in the same transaction as tournament,
  ranking, game, and result rows.
- Rollback must remove the audit row after all associated import data is removed.
