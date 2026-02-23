# Old-Method Current Ranking (Console Command)

## Goal
`app:ranking:old-method-current` calculates the **current ranking** as if the old selection method had always been used.

Only PFS tables are used:
- `PFSRANKING`
- `PFSTOURS`
- `PFSTOURWYN`
- `PFSPLAYER`

## Reference point ("current")
1. Find the latest published ranking snapshot:
   - `MAX(turniej)` from `PFSRANKING` where `rtype = 'f'`.
2. Use that tournament as the current reference tournament.
3. Read its date (`PFSTOURS.dt`) as the ranking reference date.

## Time window
1. Build a 2-year window:
   - window end = reference date
   - window start = reference date minus 2 years
2. Consider only tournaments in `PFSTOURS` with `dt` inside `[window start, window end]`.

## Old method selection logic (per player)
From `PFSTOURWYN` rows in the 2-year window:
1. Sort tournaments by recency:
   - `dt DESC`, then `turniej DESC`.
2. Traverse rows in that order and keep tournaments while total games does not exceed 200.
3. If adding the next tournament would push total above 200, selection stops for that player.
4. After selection:
   - if selected games < 30, player is not listed.

This implements the "old" policy: **most recent tournaments first** (not "best rank first").

## Rank formula
For selected tournaments of a player:
- weighted sum = `SUM(tw.trank * tw.games)`
- games sum = `SUM(tw.games)`
- exact rank = `weighted sum / games sum`
- displayed rank = rounded to integer with half-up (`PHP_ROUND_HALF_UP`)

## Ordering in output
Players are sorted by:
1. exact rank descending
2. games descending
3. `PFSPLAYER.name_alph` ascending

Position is assigned after sorting (`1..N`).

## Assumptions
1. Per-tournament achieved rank is read from `PFSTOURWYN.trank`.
2. The command simulates the old-vs-new difference specifically in tournament **selection order** for the 2-year / 200-games cap.
3. It does not write anything to MySQL (read-only usage).
