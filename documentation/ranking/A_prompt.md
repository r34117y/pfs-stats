You are a senior PHP/Symfony engineer and data-oriented developer. Implement “Approach A” for ranking qualification based on rating uncertainty (Glicko-2 style RD threshold), using historical Scrabble games data.

GOAL
Build a Symfony Console Command that:
1) Computes Glicko-2 rating + RD for all players in sliding 2-year windows (exactly like the federation’s “only last 2 years count” rule).
2) Calibrates a sensible RD_max threshold from historical data, targeting a user-defined confidence level (e.g., 95% or 99%) to minimize “inflated rankings after ~30-40 games”.
3) Outputs well-formatted CLI tables and generates a professional report (Markdown + JSON; optionally HTML) with summary metrics, plots-as-data (no images needed), and recommended RD_max + “typical equivalent games count” distribution.

TECH CONTEXT
- Symfony 6/7 app, PHP 8.2+.
- Use Doctrine DBAL (preferred) or ORM (acceptable) to read data.
- No external services. Keep compute local.
- Emphasize clean architecture: services, repositories, DTOs, value objects, pure functions for rating updates.
- Provide good performance: dataset ~300k games, ~1000 tournaments, many years.
- Add progress indicator.
- Add deterministic results (seed where randomness exists).

ASSUMED DATA (ADAPTABLE)
There is a MySQL DB named m1126_scrabble. You can call it in mysql docker container or using `doctrine:query:sql` in php container. Get familiar with schema.

WINDOW RULE
A “window” is a 2-year interval [windowStart, windowEnd], inclusive. Games outside are ignored.
We analyze many windows over history:
- default step: 1 month (configurable)
- window size: 2 years (configurable)
  We need sliding windows to calibrate RD_max across history.

Glicko-2 (CORE)
Implement Glicko-2 update per game (or per rating period). For simplicity and because we want per-game evolution:
- Treat each game as a rating period of one match.
- Use standard Glicko-2 constants:
    - initial rating r0 = 1500
    - initial RD RD0 = 350
    - initial volatility sigma0 = 0.06
    - system constant tau default 0.5 (configurable)
- Provide a tested implementation:
    - convert to Glicko-2 scale (mu, phi)
    - compute g(phi), E(mu, mu_j, phi_j)
    - update volatility (iterative algorithm)
    - update phi, mu
    - convert back to rating r and RD
      IMPORTANT: handle inactivity within a window:
- When a player has no games for some time, RD increases (per Glicko-2 pre-rating period step).
- Because we treat each game sequentially, implement a “time decay” based on elapsed days between games INSIDE the window.
- Add a configurable RD inflation per day (or treat each day as a rating period). Choose a practical approach and explain it in report assumptions.

CALIBRATION OF RD_max
We want RD_max such that probability of “inflated top” is <= alpha, where alpha = 0.05 for 95% and 0.01 for 99%.
Define “inflated top” event with parameters:
- K = top list size (default 50; configurable)
- n_early = early games count threshold (default 35; configurable; we will evaluate around 30-40)
- stable_n = stable games threshold (default 120; configurable) OR “all games available in window” if < stable_n, but require min stable_n for stability sample.
  Event occurs in a window when a player:
- appears in Top-K by rating after n_early games within that window,
- but is NOT in Top-K in “stable ranking” (computed after stable_n games, or after full window for players with >= stable_n),
- optionally requiring a minimum drop (deltaRank >= 30 OR deltaRating >= 100) – make configurable.
  We then compute:
  P(inflated_top | RD <= RD_max) across all windows and players eligible for the condition.
  Goal: find smallest RD_max such that this probability <= alpha (or choose RD_max that satisfies it with margin).
  Also report tradeoffs:
- coverage: fraction of players that qualify (RD <= RD_max)
- median games to qualify, and percentiles.

OUTPUTS
CLI:
- Print configuration summary.
- Progress bar for window processing.
- Tables:
    1) Candidate RD_max grid vs metrics (inflated probability, coverage, median games-to-qualify, p90 games-to-qualify).
    2) Final recommendation for each confidence level requested.
- Provide nice formatting: SymfonyStyle tables, aligned numbers, percent formatting.

REPORT
Generate report files in var/reports/pfs-rd-calibration/ with timestamp:
- report.md (human-readable, professional)
- report.json (machine-readable)
  OPTIONAL: report.html if easy (convert markdown or simple template).
  Report must include:
1) Executive summary:
    - recommended RD_max for 95% and/or 99%
    - “typical equivalent games” (median/p75/p90 of games needed to reach RD_max)
2) Methodology:
    - describe Glicko-2 assumptions (initial values, tau, time-decay model)
    - define windows, K, n_early, stable_n, inflated_top definition
3) Results:
    - calibration grid and chosen RD_max
    - curves as data arrays: RD_max -> inflated_prob, coverage, games_to_qualify percentiles
    - sensitivity: show results for n_early in {30, 35, 40} if feasible
4) Limitations and next steps:
    - e.g., per-game rating periods vs tournament periods, time decay assumptions, etc.

COMMAND API
Create command:
php bin/console pfs:rank:calibrate-rd
Options:
--start=YYYY-MM-DD (default: earliest game date)
--end=YYYY-MM-DD (default: latest game date)
--step=P1M (ISO 8601 interval, default: P1M)
--window=P2Y (default: P2Y)
--k=50
--early-games=35
--stable-games=120
--alpha=0.05  (can be repeated: --alpha=0.05 --alpha=0.01)
--tau=0.5
--seed=1234
--out-dir=var/reports/...
--rd-grid="350,320,300,280,...,60" OR auto-generate range (default: 350..60 step -10)
--min-games-for-player=5 (ignore tiny samples in calibration)
--min-stable-games=120 (for stable ranking reference set)
--delta-rank=30
--delta-rating=100
--format=md,json,html (default md,json)

ARCHITECTURE REQUIREMENTS
Implement in a clean, testable way:
- Domain:
    - Value objects: PlayerRatingState (rating, rd, sigma, lastPlayedAt)
    - Glicko2Service (pure functions; no DB calls)
    - WindowDefinition (start,end)
    - CalibrationResult DTOs
- Infrastructure:
    - GamesRepository (DBAL queries streaming by window, ordered by played_at then id)
    - ReportWriter (Markdown/JSON)
- Application:
    - CalibrateRdCommand orchestrates, no heavy logic inside command.

PERFORMANCE
- Use streaming queries and avoid loading all games at once.
- For each window, process games ordered chronologically.
- Consider caching player states per window, but mind memory.
- It’s acceptable to process windows sequentially; add timing in report.
- Provide a “dry-run” mode that only prints planned windows count.

TESTS
Add PHPUnit tests for:
- Glicko-2 update for a known reference scenario (create small fixture and assert approximate values).
- Calibration event detection logic on a synthetic mini dataset.
- ReportWriter outputs expected keys/sections.

DELIVERABLES
- All necessary PHP classes, command registration, services configuration if needed.
- Provide example console output in README snippet.
- Provide example report.md structure.
- Make code production-ready, with PHPDoc and strict types.

IMPORTANT
- If any assumption is needed (e.g., handling inactivity RD inflation), implement it as a configurable strategy and clearly document in report.
- Avoid external libs for rating math unless absolutely necessary; implement core math in-house.
- Use bc math only if needed; doubles are fine.

Now implement it.
