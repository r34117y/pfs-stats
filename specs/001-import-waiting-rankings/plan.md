# Implementation Plan: Import Waiting Rankings

**Branch**: `001-import-waiting-rankings` | **Date**: 2026-08-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-import-waiting-rankings/spec.md`

## Summary

Extend the existing PFS single-tournament import so each imported tournament
stores both official current-method waiting-list rows (`w`) parsed from the PFS
ranking tab and calculated old-method waiting-list rows (`u`) from the documented
ranking-method divergence point onward. Ensure latest-import rollback removes
all ranking rows created by the import, including `f`, `w`, `n`, and `u`, and
reuse existing PFS website, parser, payload, import, rollback, player matching,
and old-method ranking services.

## Technical Context

**Language/Version**: PHP 8.3+

**Primary Dependencies**: Symfony Console, Doctrine DBAL, API Platform domain
types already used by tournament import, existing PFS parser/client services

**Storage**: Existing PostgreSQL tables, primarily `ranking`,
`tournament`, `tournament_result`, `tournament_game`, `player`, and
`text_resource` audit rows

**Testing**: PHPUnit 13 via existing service and command test structure

**Target Platform**: Server-side Symfony console workflow in the existing
application container/runtime

**Project Type**: Symfony web-service application with console import workflows

**Performance Goals**: A normal single-tournament import completes without
manual post-processing; old-method waiting-list calculation reuses the existing
snapshot simulation and remains bounded to PFS tournaments up to the imported
tournament.

**Constraints**: Must preserve official PFS provenance, avoid duplicate ranking
rows, keep ranking import transactional with tournament import, and keep rollback
complete for the latest import audit.

**Scale/Scope**: PFS historical tournament corpus; one tournament imported per
`app:pfs:tournaments:import` invocation, with ranking rows for main and waiting
lists.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Competition History Is the Product**: PASS. The feature restores incomplete
  historical ranking data for PFS tournaments.
- **Official Sources and Verifiable Imports**: PASS. Current-method waiting rows
  come from the official PFS tournament ranking tab; old-method waiting rows use
  documented local ranking reconstruction.
- **Cache Correctness Before Cache Speed**: PASS. Import and rollback continue
  through existing cache warmup/invalidation launcher.
- **Symfony and API Platform Boundaries**: PASS. Work stays in console command,
  parser/payload services, import/rollback services, and old-method ranking
  service.
- **Tested Behavior Is Required**: PASS. The plan calls for parser, import,
  old-method calculation, and rollback regression tests.

## Project Structure

### Documentation (this feature)

```text
specs/001-import-waiting-rankings/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── console-workflow.md
└── tasks.md
```

### Source Code (repository root)

```text
src/
├── Command/
│   └── TournamentImport/
│       ├── ImportPfsTournamentCommand.php
│       └── RevertLatestTournamentImportCommand.php
├── PfsTournamentImport/
│   ├── ParsedTournamentRankingRow.php
│   └── ParsedTournamentResults.php
├── Ranking/
│   └── Domain/
│       └── RankingType.php
└── Service/
    ├── OldMethodCurrentRanking/
    │   ├── OldMethodCurrentRankingServiceInterface.php
    │   └── OldMethodCurrentRankingServicePostgres.php
    ├── PfsTournamentWebsiteClient.php
    ├── PfsTournamentCalendarParser.php
    ├── PfsTournamentResultsParser.php
    ├── PfsTournamentPayloadFactory.php
    ├── PlayerCatalogService.php
    ├── PfsNameNormalizer.php
    ├── TournamentRoundImportService.php
    └── TournamentRoundRollbackService.php

tests/
├── Command/
│   └── TournamentImport/
├── Service/
└── Ranking/
    └── Application/
```

**Structure Decision**: Use the existing Symfony console/import service layout.
Do not add a new calendar-wide command in this plan; the requested behavior is
integrated into `ImportPfsTournamentCommand.php` and reverted by
`RevertLatestTournamentImportCommand.php`.

## Complexity Tracking

No constitution violations.

## Phase 0 Research Summary

See [research.md](./research.md).

## Phase 1 Design Summary

See [data-model.md](./data-model.md),
[contracts/console-workflow.md](./contracts/console-workflow.md), and
[quickstart.md](./quickstart.md).

## Post-Design Constitution Check

- **Competition History Is the Product**: PASS. Data model covers official and
  old-method waiting rankings.
- **Official Sources and Verifiable Imports**: PASS. Contracts require official
  current waiting-list parsing and deterministic old-method reconstruction.
- **Cache Correctness Before Cache Speed**: PASS. Quickstart includes import and
  rollback checks that use existing cache warmup behavior.
- **Symfony and API Platform Boundaries**: PASS. Responsibilities are assigned
  to existing services and commands.
- **Tested Behavior Is Required**: PASS. Quickstart and research define focused
  regression coverage before implementation completion.
