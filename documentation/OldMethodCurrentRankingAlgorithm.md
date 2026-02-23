# Old-Method Current Ranking (Console Command)

## Goal
`app:ranking:old-method-current` calculates the **current ranking** as if the old method had never been replaced by the new one.

This includes correcting post-`202305070` rank inflation by recomputing rankings tournament-by-tournament.

Only PFS tables are used:
- `PFSRANKING`
- `PFSTOURS`
- `PFSTOURWYN`
- `PFSTOURHH`
- `PFSPLAYER`

## Why direct re-sorting is not enough
From tournament `202305070` onward, database ranking snapshots are based on the new method (best tournaments under 200 games), which inflates ranks.

Therefore:
- We do **not** trust post-`202305070` ranking values from `PFSRANKING` as historical truth for old-method simulation.
- We recompute intermediate rankings sequentially from `202305070` to the latest tournament.

## High-level procedure
1. Read latest published ranking tournament id from `PFSRANKING` (`rtype = 'f'`) -> reference tournament.
2. Build historical baseline from tournaments `< 202305070`:
   - tournament achieved ranks from `PFSTOURWYN.trank`
   - old snapshot seed from last `PFSRANKING` before `202305070`
3. For each tournament from `202305070` to reference tournament (ordered by `dt`, `id`):
   - compute tournament pre-ranks for participants using simulated old-method state
   - compute tournament achieved rank from game-level scalps (`PFSTOURHH`) using Hollington + 50-point exception
   - append tournament result to player history
   - rebuild ranking snapshot after this tournament using old-method selection
4. Output final snapshot for reference tournament.

## Tournament pre-rank assignment (for simulation)
For each participant before a simulated tournament:
1. If player is on current simulated ranking list: use that rank, but clamp to minimum `100` for tournament seeding.
2. Else if career games >= 30: use a tournament rank from most recent tournaments until at least 30 games (weighted by games).
3. Else if career games in `[1..29]`: temporary rank = `(careerScalps + missingGames*100) / 30`.
4. Else (debut): rank `100`.

## Game scalp formula used in simulation
For each unique game (`PFSTOURHH`, deduplicated per round/pair):
- Win: opponent rank + 50
- Loss: opponent rank - 50
- Draw: opponent rank

Exception:
- If rank difference > 50 and higher-ranked player wins, both players receive their own pre-tournament rank as scalp.

Player tournament achieved rank is:
- `sum(scalps from all tournament games) / number_of_games`

## Old-method ranking rebuild after each simulated tournament
For each player:
1. Take tournaments from last 2 years relative to current tournament date.
2. Sort by recency (`date DESC`, then `tournamentId DESC`).
3. Take prefix while total games <= 200 (stop when next tournament would exceed 200).
4. If selected games < 30, player is excluded.
5. Exact rank = weighted average of selected tournament achieved ranks (`SUM(rank*games)/SUM(games)`).

## Final output ordering
Final current ranking rows are ordered by:
1. exact rank descending
2. games descending
3. `PFSPLAYER.name_alph` ascending

Displayed rank is rounded half-up to integer; ordering compares exact rank.

## Notes
- MySQL is read-only; command performs read-only simulation in memory.
- `PFSRANKING` is used as:
  - seed snapshot before `202305070`
  - latest reference tournament locator
- Post-`202305070` ranking snapshots from DB are not reused as simulated old-method truth.
