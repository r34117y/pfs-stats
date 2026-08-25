# Tasks: Import Waiting Rankings

**Input**: Design documents from `/specs/001-import-waiting-rankings/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Tests**: Regression tests are required by FR-015 and the constitution.

**Organization**: Tasks are grouped by user story to enable independent
implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel with other marked tasks in the same phase because
  it touches different files and has no dependency on incomplete tasks.
- **[Story]**: User story label for story-phase tasks only.
- Every task includes an exact repository-relative file path.

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the current import/ranking surface before changing behavior.

- [X] T001 Inspect current ranking parser behavior and fixture shape in tests/Service/PfsTournamentResultsParserTest.php
- [X] T002 Inspect current payload ranking mapping in tests/Service/PfsTournamentPayloadFactoryTest.php
- [X] T003 Inspect old-method snapshot behavior in src/Service/OldMethodCurrentRanking/OldMethodCurrentRankingServicePostgres.php
- [X] T004 Inspect latest-import rollback delete scope in src/Service/TournamentRoundRollbackService.php

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Establish shared contracts and helpers needed by all user stories.

**CRITICAL**: No user story work can begin until this phase is complete.

- [X] T005 Add or update shared ranking count reporting DTO/array shape documentation in src/Service/TournamentRoundImportService.php
- [X] T006 [P] Add test fixture helper for PFS ranking HTML with main and waiting sections in tests/Service/PfsTournamentResultsParserTest.php
- [X] T007 [P] Add test fixture helper for tournament import payloads with `f`, `w`, `n`, and `u` ranking rows in tests/Service/TournamentRoundImportServiceTest.php
- [X] T008 [P] Add test fixture helper for rollback audit rows with all ranking types in tests/Service/TournamentRoundRollbackServiceTest.php

**Checkpoint**: Foundation ready; user story implementation can now begin.

---

## Phase 3: User Story 1 - Backfill Missing Official Waiting Lists (Priority: P1) MVP

**Goal**: Imported PFS tournaments store official current-method waiting-list
rows as ranking type `w` from the official ranking tab.

**Independent Test**: Parse a PFS ranking tab with a waiting-list section, import
the tournament payload, and verify type `w` rows are stored without duplicate or
main-ranking replacement behavior.

### Tests for User Story 1

- [X] T009 [P] [US1] Add parser regression test for "Poczekalnia na dzień" rows marked as waiting list in tests/Service/PfsTournamentResultsParserTest.php
- [X] T010 [P] [US1] Add payload factory regression test preserving `main=false` waiting rows in tests/Service/PfsTournamentPayloadFactoryTest.php
- [X] T011 [P] [US1] Add import service regression test inserting official waiting rows as `RankingType::FUCKED_WAITING` in tests/Service/TournamentRoundImportServiceTest.php
- [X] T012 [P] [US1] Add import service regression test preserving existing `RankingType::FUCKED` main rows while inserting `RankingType::FUCKED_WAITING` in tests/Service/TournamentRoundImportServiceTest.php

### Implementation for User Story 1

- [X] T013 [US1] Update ranking parser waiting-list marker handling in src/Service/PfsTournamentResultsParser.php
- [X] T014 [US1] Update parsed ranking row construction defaults for main/waiting rows in src/PfsTournamentImport/ParsedTournamentRankingRow.php
- [X] T015 [US1] Update payload ranking mapping to preserve waiting-list metadata in src/Service/PfsTournamentPayloadFactory.php
- [X] T016 [US1] Update official ranking insert flow for `f` and `w` rows in src/Service/TournamentRoundImportService.php
- [X] T017 [US1] Run focused parser and payload tests with php bin/phpunit tests/Service/PfsTournamentResultsParserTest.php tests/Service/PfsTournamentPayloadFactoryTest.php

**Checkpoint**: User Story 1 is complete when a single PFS import stores
official waiting-list rows as type `w` and existing type `f` behavior still
passes.

---

## Phase 4: User Story 2 - Reconstruct Old Ranking Waiting Lists (Priority: P2)

**Goal**: Old-method ranking snapshots store main old-method rows as `n` and
below-threshold waiting-list rows as `u` from the documented divergence point.

**Independent Test**: Calculate an old-method snapshot at or after
"II Wielicki Turniej w Scrabble" and verify `u` rows exist for below-threshold
players while earlier tournaments do not require separate `u` rows.

### Tests for User Story 2

- [X] T018 [P] [US2] Add old-method service test for returning separate main and waiting snapshot rows in tests/Service/OldMethodCurrentRankingServicePostgresTest.php
- [X] T019 [P] [US2] Add old-method service test for no type `u` requirement before tournament id 9367 in tests/Service/OldMethodCurrentRankingServicePostgresTest.php
- [X] T020 [P] [US2] Add import service regression test inserting `RankingType::NORMAL` and `RankingType::NORMAL_WAITING` rows in tests/Service/TournamentRoundImportServiceTest.php

### Implementation for User Story 2

- [X] T021 [US2] Extend old-method snapshot return contract with waiting rows in src/Service/OldMethodCurrentRanking/OldMethodCurrentRankingServiceInterface.php
- [X] T022 [US2] Split old-method snapshot rebuilding into main and waiting row sets in src/Service/OldMethodCurrentRanking/OldMethodCurrentRankingServicePostgres.php
- [X] T023 [US2] Update old-method current ranking loading to continue reading only `RankingType::NORMAL` rows in src/Service/OldMethodCurrentRanking/OldMethodCurrentRankingServicePostgres.php
- [X] T024 [US2] Update tournament import old-method insertion to write `RankingType::NORMAL` and `RankingType::NORMAL_WAITING` rows in src/Service/TournamentRoundImportService.php
- [X] T025 [US2] Update old-method backfill command to preserve existing `n` behavior while optionally supporting `u` rows in src/Command/BackfillOldMethodRankingSnapshotsCommand.php
- [X] T026 [US2] Run focused old-method and import tests with php bin/phpunit tests/Service/OldMethodCurrentRankingServicePostgresTest.php tests/Service/TournamentRoundImportServiceTest.php

**Checkpoint**: User Story 2 is complete when imported post-divergence
tournaments store type `u` rows and pre-divergence behavior remains unchanged.

---

## Phase 5: User Story 3 - Review Backfill Results Safely (Priority: P3)

**Goal**: Maintainers can verify import and rollback effects through clear
summary counts for ranking types and source/import outcomes.

**Independent Test**: Run the import on a mixed fixture and verify summary counts
for ranking types; run latest-import rollback and verify all ranking types for
the imported tournament are removed and reported.

### Tests for User Story 3

- [X] T027 [P] [US3] Add command test for import output showing ranking counts for `f`, `w`, `n`, and `u` in tests/Command/TournamentImport/ImportPfsTournamentCommandTest.php
- [X] T028 [P] [US3] Add rollback service regression test deleting ranking rows for `f`, `w`, `n`, and `u` in tests/Service/TournamentRoundRollbackServiceTest.php
- [X] T029 [P] [US3] Add command test for rollback output preserving deleted ranking row summary in tests/Command/TournamentImport/RevertLatestTournamentImportCommandTest.php

### Implementation for User Story 3

- [X] T030 [US3] Return per-ranking-type inserted counts from import service in src/Service/TournamentRoundImportService.php
- [X] T031 [US3] Display ranking counts for `f`, `w`, `n`, and `u` in import command output in src/Command/TournamentImport/ImportPfsTournamentCommand.php
- [X] T032 [US3] Ensure rollback summary counts all ranking rows deleted for the audited tournament in src/Service/TournamentRoundRollbackService.php
- [X] T033 [US3] Ensure rollback command output remains clear for all deleted ranking types in src/Command/TournamentImport/RevertLatestTournamentImportCommand.php
- [X] T034 [US3] Run focused command and rollback tests with php bin/phpunit tests/Command/TournamentImport tests/Service/TournamentRoundRollbackServiceTest.php

**Checkpoint**: User Story 3 is complete when maintainers can see import ranking
counts and rollback removes every ranking type created by the import.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Final verification and documentation cleanup across the feature.

- [X] T035 [P] Update quickstart validation notes if command output differs in specs/001-import-waiting-rankings/quickstart.md
- [X] T036 [P] Update project documentation for PFS ranking types `w` and `u` behavior in documentation/General.md
- [ ] T037 Run full relevant service suite with php bin/phpunit tests/Service
- [ ] T038 Run full relevant ranking suite with php bin/phpunit tests/Ranking tests/Api/RankingEndpointTest.php tests/Api/OldRankingEndpointTest.php
- [X] T039 Run console command regression suite with php bin/phpunit tests/Command
- [X] T040 Review git diff for unrelated changes and verify only intended files changed in .specify/feature.json specs/001-import-waiting-rankings/tasks.md src/ tests/ documentation/General.md

**Validation note**: T037 was attempted and failed on pre-existing-looking
unrelated service assertions in `ClubTournamentResultsParserTest` and
`PfsTournamentImportMatcherTest`. T038 was attempted, produced early failures,
then did not finish after several minutes and was terminated.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: Depends on Setup completion and blocks all user
  stories.
- **User Story 1 (Phase 3)**: Depends on Foundational; this is the MVP.
- **User Story 2 (Phase 4)**: Depends on Foundational and can start after shared
  fixtures exist, but final integration depends on the import service edits from
  US1.
- **User Story 3 (Phase 5)**: Depends on US1 and US2 for complete ranking count
  reporting.
- **Polish (Phase 6)**: Depends on desired user stories being complete.

### User Story Dependencies

- **US1 (P1)**: Provides official `w` import and is the MVP.
- **US2 (P2)**: Adds calculated `u`; can be developed mostly independently in
  old-method service tests, then integrated into import.
- **US3 (P3)**: Adds maintainer summaries and rollback regression coverage after
  ranking type behavior exists.

### Parallel Opportunities

- T006, T007, and T008 can run in parallel after setup.
- T009, T010, T011, and T012 can be written in parallel because they target
  separate test concerns.
- T018, T019, and T020 can be written in parallel because they target old-method
  calculation and import integration tests.
- T027, T028, and T029 can be written in parallel because they target command and
  rollback surfaces.
- T035 and T036 can run in parallel during polish.

---

## Parallel Example: User Story 1

```bash
Task: "T009 [US1] Add parser regression test in tests/Service/PfsTournamentResultsParserTest.php"
Task: "T010 [US1] Add payload factory regression test in tests/Service/PfsTournamentPayloadFactoryTest.php"
Task: "T011 [US1] Add import service regression test in tests/Service/TournamentRoundImportServiceTest.php"
Task: "T012 [US1] Add import service preservation test in tests/Service/TournamentRoundImportServiceTest.php"
```

## Parallel Example: User Story 2

```bash
Task: "T018 [US2] Add old-method main/waiting snapshot test in tests/Service/OldMethodCurrentRankingServicePostgresTest.php"
Task: "T019 [US2] Add pre-divergence no-u test in tests/Service/OldMethodCurrentRankingServicePostgresTest.php"
Task: "T020 [US2] Add import service n/u insertion test in tests/Service/TournamentRoundImportServiceTest.php"
```

## Parallel Example: User Story 3

```bash
Task: "T027 [US3] Add import command output test in tests/Command/TournamentImport/ImportPfsTournamentCommandTest.php"
Task: "T028 [US3] Add rollback service all-ranking-types test in tests/Service/TournamentRoundRollbackServiceTest.php"
Task: "T029 [US3] Add rollback command output test in tests/Command/TournamentImport/RevertLatestTournamentImportCommandTest.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 and Phase 2.
2. Complete Phase 3 for official waiting-list import as type `w`.
3. Stop and validate parser, payload, and import tests for US1.
4. Confirm a single imported PFS tournament stores official waiting-list rows.

### Incremental Delivery

1. Add US1 to restore official current-method waiting lists.
2. Add US2 to calculate and store old-method waiting lists.
3. Add US3 to expose clear import and rollback summaries.
4. Run polish verification and documentation updates.

### Implementation Notes

- Write test tasks before implementation tasks in each story and verify they fail
  for the expected reason.
- Keep import and rollback changes transactionally scoped to the audited
  tournament.
- Reuse existing PFS and ranking services instead of adding a second parsing or
  import path.
