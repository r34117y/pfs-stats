# Research: Import Waiting Rankings

## Decision: Extend the existing single-tournament import path

The import behavior will be added to `ImportPfsTournamentCommand.php` through
the existing parser, payload factory, and `TournamentRoundImportService` path.

**Rationale**: The command already fetches a specific PFS tournament, parses the
ranking tab, builds a `TournamentRound` payload, imports tournament data and
rankings transactionally, writes import audit data, and launches cache warmup.
Adding waiting rankings here ensures new imports are complete immediately.

**Alternatives considered**:

- Add a separate calendar-wide waiting-list backfill command. Rejected for this
  plan because the user specifically asked that the existing import command
  import `w` and calculate `u`.
- Import waiting rankings manually after each tournament. Rejected because it is
  error-prone and violates the feature goal of a complete import workflow.

## Decision: Reuse PFS website and parsing services

Use `PfsTournamentWebsiteClient` for PFS HTML retrieval,
`PfsTournamentResultsParser` for ranking-tab parsing, and
`PfsTournamentPayloadFactory` for transferring parsed ranking rows into the
existing import payload.

**Rationale**: These services already encapsulate PFS URL fetching, result page
parsing, and payload shaping. `PfsTournamentResultsParser::parseRanking()` already
tracks whether a parsed row is in the main ranking or appears after the
"Poczekalnia na dzień" marker, and `TournamentRoundImportService` already maps
non-main rows to `RankingType::FUCKED_WAITING`.

**Alternatives considered**:

- Add a second ranking-tab parser. Rejected because it would duplicate source
  parsing rules and increase drift risk.
- Parse waiting-list rows in the command. Rejected because commands should
  orchestrate services rather than own parsing logic.

## Decision: Make old-method snapshots return main and waiting rows

Extend `OldMethodCurrentRankingServiceInterface` and
`OldMethodCurrentRankingServicePostgres` so snapshots can expose players with at
least 30 games as main old-method rows (`n`) and players below the threshold as
old-method waiting rows (`u`), starting at tournament id `9367`.

**Rationale**: The service already simulates old-method ranking history from the
documented divergence point. Its current `rebuildSnapshot()` method filters out
players below 30 games before building rows, which is appropriate for the main
list but prevents storing type `u`. The smallest design change is to preserve a
second below-threshold snapshot while keeping the existing main-list behavior.

**Alternatives considered**:

- Recalculate `u` directly in `TournamentRoundImportService`. Rejected because
  old-method ranking logic already lives in the old-method service.
- Store all old-method rows as `n` and infer waiting status at read time.
  Rejected because ranking type semantics already define `u` as a stored type.

## Decision: Store all import-created ranking types under the imported tournament

For each imported tournament, `TournamentRoundImportService` will store official
current rows as `f` and `w`, then store old-method rows as `n` and `u` when the
old-method snapshot exists.

**Rationale**: The `ranking` table has a uniqueness constraint on organization,
ranking type, player, and tournament. Storing each list as its own type preserves
current API behavior and allows rollback to delete all rows for the imported
tournament with one tournament-scoped delete.

**Alternatives considered**:

- Store old-method waiting rows without tournament association. Rejected because
  ranking history and rollback both depend on tournament-scoped snapshots.

## Decision: Keep rollback tournament-scoped

`TournamentRoundRollbackService` should continue deleting all `ranking` rows for
the audited tournament, which covers `f`, `w`, `n`, and `u`. Tests should assert
that all four ranking types are removed by latest-import rollback.

**Rationale**: The current delete statement is intentionally broad by tournament
and organization. It is already compatible with the new ranking types, but the
behavior needs regression coverage because rollback completeness is a requested
acceptance requirement.

**Alternatives considered**:

- Add explicit deletes per ranking type. Rejected because it creates more code
  without improving correctness while the tournament-scoped delete is clearer.

## Decision: Use focused regression tests over live-network tests

Tests should use representative HTML fixtures and local database fixtures rather
than relying on live PFS website availability.

**Rationale**: The PFS website is the source of truth, but automated tests need
repeatable inputs. The existing parser tests already use inline HTML fixtures
that can be extended with a waiting-list section.

**Alternatives considered**:

- Test against live `pfs.org.pl` pages. Rejected because network availability
  and source page changes would make regression tests unstable.
