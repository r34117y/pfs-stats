# Feature Specification: Import Waiting Rankings

**Feature Branch**: `speckit`

**Created**: 2026-08-25

**Status**: Draft

**Input**: User description: "The database lacks ranking entries for waiting list (type \"w\" and \"u\"). Write a console command that will iterate over the tournaments in tournament calendar (https://www.pfs.org.pl/kalendarz.php), check the results page for each tournament (https://www.pfs.org.pl/turniej.php?id={id}, \"Ranking\" tab) (two most recent tournaments are not imported yet, so skip them), below the main ranking there is the waiting list. make sure that it was not imported and import it as ranking with type \"w\". starting with tournament \"II Wielicki Turniej w Scrabble\" calculate the waiting list for the old ranking (type \"u\" - see documentation/General.md)"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Backfill Missing Official Waiting Lists (Priority: P1)

As a site maintainer, I want to populate missing official PFS waiting-list
rankings for imported tournaments so player ranking history includes players
with fewer than 30 eligible games.

**Why this priority**: The current database has a known gap for ranking type
`w`, which makes official ranking history incomplete for developing players.

**Independent Test**: Can be tested by running the backfill against a known set
of imported tournaments whose official ranking pages include waiting lists, then
verifying that missing type `w` entries are created and existing entries are not
duplicated or changed unexpectedly.

**Acceptance Scenarios**:

1. **Given** an imported historical PFS tournament with an official waiting list
   on its ranking page and no stored type `w` entries, **When** the maintainer
   runs the backfill, **Then** the tournament has stored type `w` ranking entries
   matching the official waiting list.
2. **Given** an imported historical PFS tournament that already has type `w`
   entries, **When** the maintainer runs the backfill again, **Then** no duplicate
   type `w` entries are created and existing valid data remains stable.
3. **Given** the two most recent tournaments from the PFS tournament calendar,
   **When** the backfill evaluates tournament eligibility, **Then** those
   tournaments are skipped because they are not expected to be imported yet.

---

### User Story 2 - Reconstruct Old Ranking Waiting Lists (Priority: P2)

As a site maintainer, I want old-method waiting-list rankings to be calculated
from the ranking-rule change point onward so historical comparisons between
current and old ranking methods remain complete.

**Why this priority**: The project explicitly maintains old ranking type `n`;
without matching waiting-list type `u`, the old ranking history is incomplete
for players below the main ranking threshold.

**Independent Test**: Can be tested by selecting tournaments beginning with
"II Wielicki Turniej w Scrabble" and verifying that each eligible tournament has
old-method waiting-list entries calculated consistently with the documented old
ranking rules.

**Acceptance Scenarios**:

1. **Given** "II Wielicki Turniej w Scrabble" or a later imported PFS tournament,
   **When** the backfill processes old-method rankings, **Then** the tournament
   has type `u` waiting-list ranking entries for players who belong on the
   old-method waiting list.
2. **Given** an imported PFS tournament before "II Wielicki Turniej w Scrabble",
   **When** the backfill processes old-method rankings, **Then** no separate
   type `u` entries are required for that tournament because the documented
   method split had not yet occurred.

---

### User Story 3 - Review Backfill Results Safely (Priority: P3)

As a site maintainer, I want a clear summary of what the backfill examined,
skipped, imported, calculated, and ignored so I can verify the operation before
trusting the restored ranking history.

**Why this priority**: The backfill changes historical ranking data. A concise
summary lets maintainers detect source-page gaps, skipped recent tournaments,
and idempotency behavior without inspecting every record manually.

**Independent Test**: Can be tested by running the backfill on a mixed dataset
containing missing waiting lists, already imported waiting lists, recent
tournaments, and source pages without waiting-list data, then confirming the
summary counts and messages match those outcomes.

**Acceptance Scenarios**:

1. **Given** a backfill run with a mix of imported, skipped, and unchanged
   tournaments, **When** the run completes, **Then** the maintainer receives a
   summary with counts for examined tournaments, skipped recent tournaments,
   imported type `w` entries, calculated type `u` entries, unchanged tournaments,
   and source-page problems.
2. **Given** a tournament source page cannot be read or does not contain a
   usable waiting list, **When** the backfill reaches that tournament, **Then**
   the run records the problem and continues processing remaining eligible
   tournaments.

### Edge Cases

- The calendar contains tournament identifiers ordered by registration rather
  than event date.
- A tournament is present in the official calendar but is not yet imported in
  the local data.
- The official ranking page has a main ranking but no waiting-list section.
- The waiting-list section contains players that need to be matched to existing
  local player records.
- The official source page is temporarily unavailable, malformed, or missing the
  expected ranking tab.
- A tournament has nonstandard structure, but still publishes a ranking and
  waiting list after the tournament.
- A previous partial run imported some but not all waiting-list entries for a
  tournament.
- Running the backfill multiple times must not create duplicate rankings.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST provide a maintainer-triggered backfill workflow
  for PFS waiting-list ranking entries.
- **FR-002**: The workflow MUST inspect tournaments from the official PFS
  tournament calendar and identify corresponding tournament result pages.
- **FR-003**: The workflow MUST skip the two most recent calendar tournaments.
- **FR-004**: The workflow MUST skip calendar tournaments that are not present in
  the local imported tournament data.
- **FR-005**: For each eligible imported tournament, the workflow MUST inspect
  the ranking information published for that tournament.
- **FR-006**: When an eligible tournament has an official waiting list and local
  type `w` entries are absent, the workflow MUST import the official waiting
  list as type `w` ranking entries.
- **FR-007**: When local type `w` entries already exist for an eligible
  tournament, the workflow MUST NOT create duplicate type `w` entries.
- **FR-008**: The workflow MUST preserve existing main ranking entries and MUST
  NOT replace type `f` or type `n` rankings while importing waiting lists.
- **FR-009**: Starting with "II Wielicki Turniej w Scrabble", the workflow MUST
  calculate old-method waiting-list rankings and store them as type `u`.
- **FR-010**: The workflow MUST NOT require separate type `u` entries before the
  documented old-ranking divergence point.
- **FR-011**: The workflow MUST apply the documented old-ranking rule that
  selects the latest tournaments in the two-year window while keeping the same
  maximum-game limit used by the current ranking.
- **FR-012**: The workflow MUST produce a completion summary that reports
  examined tournaments, skipped recent tournaments, skipped missing local
  tournaments, imported type `w` entries, calculated type `u` entries, unchanged
  tournaments, and source-page problems.
- **FR-013**: The workflow MUST continue with remaining eligible tournaments
  when a single tournament source page cannot be read or parsed.
- **FR-014**: The workflow MUST be idempotent: repeating the same run against the
  same source data MUST leave ranking counts and values unchanged after the first
  successful import or calculation.
- **FR-015**: Ranking changes from this workflow MUST be covered by regression
  checks for official waiting-list import, old-method waiting-list calculation,
  duplicate prevention, recent-tournament skipping, and partial-source failure
  handling.

### Key Entities *(include if feature involves data)*

- **Tournament Calendar Entry**: An official PFS calendar item with a tournament
  identifier and ordering context used to decide whether the tournament is among
  the two most recent entries.
- **Imported Tournament**: A locally stored tournament that can receive ranking
  entries from official source data or calculated old-method data.
- **Ranking Entry**: A stored ranking row associated with a tournament, player,
  ranking position, ranking value, and ranking type (`f`, `w`, `n`, or `u`).
- **Official Waiting List**: The player list published below the main ranking on
  the tournament ranking page for players below the main-ranking eligibility
  threshold.
- **Old-Method Waiting List**: A calculated waiting-list ranking for the previous
  PFS ranking method, stored with type `u` from the documented divergence point
  onward.
- **Player Match**: The association between a person listed on the official
  waiting list and an existing local player record.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For a representative sample of eligible imported tournaments with
  official waiting lists, 100% of missing type `w` entries are restored to match
  the official source data.
- **SC-002**: A second run over the same source data creates 0 duplicate ranking
  entries and reports unchanged tournaments instead.
- **SC-003**: The two most recent calendar tournaments are skipped in 100% of
  runs until they are no longer among the two most recent calendar entries.
- **SC-004**: For tournaments from "II Wielicki Turniej w Scrabble" onward, 100%
  of eligible old-method waiting-list rankings are available as type `u` after a
  successful run.
- **SC-005**: A source-page failure for one tournament does not stop processing
  of later eligible tournaments.
- **SC-006**: The completion summary accounts for every examined calendar
  tournament in exactly one outcome category.

## Assumptions

- The official PFS calendar remains the authoritative list of PFS tournament
  result-page identifiers.
- The two most recent calendar tournaments are excluded globally for each run,
  regardless of whether one of them is already present locally.
- Existing local player records are sufficient to match official waiting-list
  players for imported tournaments; unmatched players are reported as
  source-page problems unless later clarification changes this behavior.
- Type `w` represents the official current-method waiting list, and type `u`
  represents the calculated old-method waiting list.
- The old-method waiting-list calculation uses the documented old ranking method:
  latest tournaments in the two-year window, with the same maximum total game
  count constraint as the current ranking.
