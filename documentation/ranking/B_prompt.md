You are a senior PHP/Symfony engineer and data-oriented developer. Implement “Approach B”: choose a sensible minimum number of games n for PFS ranking eligibility by controlling the historical rate of “false leaders” (inflated early top positions) using sliding 2-year windows.

GOAL
Build a Symfony Console Command that:
1) Evaluates candidate thresholds n_min (e.g., 10..200) on historical data using the official 2-year rule (games older than 2 years are ignored in each window).
2) Quantifies how often players appear unrealistically high in the early ranking with only ~n games (“false leaders”) and how long that inflated status would persist.
3) Chooses the smallest n_min such that the false-leader rate is below a user-defined tolerance alpha (e.g., 5% for 95% confidence, 1% for 99%).
4) Outputs polished CLI tables and generates a professional report (Markdown + JSON; optional HTML) with the recommended n_min, sensitivity analyses, and supporting metrics.

IMPORTANT CONTEXT
- Symfony 6/7, PHP 8.2+.
- Use Doctrine DBAL preferred (fast, streaming), ORM acceptable.
- Dataset: ~300k games, ~1000 tournaments, many years.
- Provide performant implementation, memory-safe, with progress bar.
- Deterministic results where randomness exists (seeded).

ASSUMED DATA (ADAPTABLE)
Relational DB has at least:
- games table:
    - id (int)
    - played_at (datetime UTC)
    - tournament_id (int, nullable)
    - player_a_id (int)
    - player_b_id (int)
    - result_a (float)  // 1=win, 0=loss, 0.5=draw if exists; else 0/1
    - result_b (float)  // can be derived as 1-result_a
      OPTIONAL:
    - round_no (int) / board_no / game_order
      If schema differs, implement a small mapping layer and document it.

RATING MODEL (FOR ANALYSIS)
We need a consistent rating model to produce rankings within each window. Use one of:
A) Elo (recommended for simplicity and speed):
- initial rating r0 = 0 (or 100); only relative differences matter
- K-factor configurable (default 20)
- expected score: E = 1 / (1 + 10^((R_op - R)/400))
- update per game
  B) (Optional) support for alternative model via interface (e.g., Glicko-2), but Elo is the default.

We are NOT implementing the official PFS rating here unless specified; we are building a calibration tool for n_min based on stability signals. Make this explicit in the report.

WINDOWS
A “window” is a 2-year interval [windowStart, windowEnd], inclusive.
We analyze many windows over history:
- step: 1 month by default (configurable ISO 8601 interval, e.g., P1M)
- window size: P2Y by default (configurable)
  Within each window, games are ordered chronologically by played_at then id.

DEFINITIONS: EARLY vs STABLE RANKING
We want to detect inflated ranking positions caused by small sample size.

Parameters:
- K (top list size): default 50 (configurable)
- n_early: early-games mark for detection (default 30/35/40; configurable; can test multiple)
- stable_n: number of games used to define “stable” ranking (default 120; configurable)
- min_stable_games: minimum games required to include player in stable ranking reference set (default = stable_n)
- delta_rank: minimum rank drop to count as “false leader” (default 30; configurable)
- delta_rating: optional minimum rating drop (default 100; configurable; can disable)
- persist_days: optional: count how many days the player remains in Top-K before dropping out (inflation persistence)

For each window:
1) Build a STABLE reference ranking:
    - Use only players with >= min_stable_games in that window.
    - Compute Elo rating using ALL their games in the window (or at least stable_n games; be consistent and document).
    - Sort by rating desc to get stable rank positions.
2) Build EARLY rankings for each candidate n_min:
    - For each player, use only their first n_min games within the window (chronological).
    - Compute Elo rating from those truncated games for all players with >= n_min games (or >= min_games_for_player).
    - Sort to get early rank positions.

FALSE LEADER EVENT (CORE)
A player counts as a “false leader” for threshold n_min in a given window if:
- The player is in early Top-K (rank_early <= K) after n_min games (or at n_early; define clearly),
  AND
- In the stable ranking (from step 1), the same player is NOT in Top-K,
  AND
- The rank drop is at least delta_rank (rank_stable - rank_early >= delta_rank),
  AND/OR rating drop is at least delta_rating (optional).
  Only consider players who exist in the stable reference set (>= min_stable_games), so we are comparing to a stable estimate.

METRICS TO COMPUTE FOR EACH n_min
Across all windows:
- false_leader_rate: (# false leader events) / (# players in early Top-K that are also in stable reference set)
- false_leader_rate_per_window distribution: mean, median, p90, p95 (to show worst-case windows)
- coverage:
    - fraction of active players in window that meet n_min (>= n_min games)
    - fraction of players that are excluded by n_min
- persistence metrics (optional but valuable):
    - For each false leader: estimate how long (days) they would remain in Top-K as more games are added:
        - Recompute early ranking at incremental counts (n_min + stepGames) for that player or at monthly checkpoints until they drop out of Top-K
        - Record persist_days; summarize mean/median/p90
- stability curve:
    - for n_min grid: arrays of (n_min -> false_leader_rate, coverage, p95 false_leader_rate_per_window, persistence p90)

CHOOSING n_min GIVEN CONFIDENCE
User provides alpha (tolerated false leader rate), e.g. 0.05 (95%), 0.01 (99%).
Pick smallest n_min such that:
- overall false_leader_rate <= alpha
  AND (preferably)
- p95 false_leader_rate_per_window <= alpha_window (default same as alpha, configurable)
  Report both and explain tradeoffs.

COMMAND API
Create command:
php bin/console pfs:rank:calibrate-min-games

Options:
--start=YYYY-MM-DD (default earliest game date)
--end=YYYY-MM-DD (default latest game date)
--step=P1M (default P1M)
--window=P2Y (default P2Y)
--model=elo (default elo)
--elo-k=20
--top-k=50
--n-grid=10..200 (or --n-grid="10,15,20,...")
--alpha=0.05 (repeatable: --alpha=0.05 --alpha=0.01)
--n-early=35 (can be repeatable or allow CSV: "30,35,40")
--stable-games=120
--min-stable-games=120
--delta-rank=30
--delta-rating=0 (0 disables)
--min-games-for-player=5 (ignore tiny samples)
--seed=1234
--out-dir=var/reports/pfs-min-games/
--format=md,json,html (default md,json)
--dry-run (prints planned windows and exits)
--max-windows=0 (0 = all; else limit for quick tests)

OUTPUT REQUIREMENTS (CLI)
- Use SymfonyStyle for pretty output.
- Print configuration summary.
- Show progress bar for window iteration.
- Print a main table:
  Columns: n_min, false_rate, p95_window_false_rate, coverage, excluded, persistence_p90_days (if enabled)
- Highlight recommended n_min for each alpha.
- Print “Top 5 worst windows” (by false leader rate) with dates and counts.

REPORT REQUIREMENTS
Write report files into out-dir with timestamp:
- report.md (professional narrative)
- report.json (all metrics and curves)
  Optional:
- report.html (simple template rendering the same content)

Report.md sections:
1) Executive summary:
    - recommended n_min for each alpha (95%, 99%)
    - key tradeoffs (false rate vs coverage)
2) Methodology:
    - windowing (2-year sliding), step
    - rating model used (Elo parameters)
    - exact definition of “false leader” and denominators
3) Results:
    - calibration grid table (top rows + link to JSON for full)
    - curves (as embedded small tables or bullet points + mention arrays in JSON)
    - worst windows list
    - sensitivity: run for n_early in {30,35,40} if provided; compare recommendations
4) Limitations and next steps:
    - Elo vs official PFS rating differences
    - tournament clustering effects, bag luck, non-independence of games
    - possible improvements: bootstrap sampling, opponent-strength weighting, or switching to Glicko-2 RD gating.

ARCHITECTURE / CLEAN CODE
Implement in a clean, testable way:
- Domain:
    - RatingModelInterface { initState(); update(stateA, stateB, resultA): [stateA’, stateB’]; }
    - EloRatingModel implements it.
    - PlayerState DTO (rating, gamesCount, lastPlayedAt maybe)
    - WindowDefinition (start,end)
    - WindowMetrics DTO, GridPoint DTO, Recommendation DTO
- Infrastructure:
    - GamesRepository (DBAL streaming queries for games in a window, ordered)
    - Optionally a PlayerRepository for counts in windows
    - ReportWriter (Markdown + JSON)
- Application:
    - CalibrationService orchestrates:
        - iterate windows
        - compute stable ranking once per window
        - compute early ranking per n_min efficiently
- Keep heavy logic out of the Console Command.

PERFORMANCE NOTES (IMPORTANT)
Naively recomputing Elo from scratch for each n_min per window can be too slow.
Implement a more efficient approach:
- For each window, process games in chronological order once and build per-player game lists (ids/indices) or incremental states.
- Consider computing early rankings for multiple n_min values by:
    - storing each player's rating trajectory after each game count (ratingAfterGameCount[k])
    - OR processing games once while maintaining snapshots at needed n_min checkpoints.
- Keep memory reasonable:
    - store only what you need for players that reach max(n_grid) games in window.
    - allow a mode that computes only needed checkpoints.

ACCURACY / EDGE CASES
- Handle draws if present.
- If a player doesn’t appear in stable reference set, exclude from false leader evaluation denominator.
- If fewer than K players qualify in early ranking for a window, adjust denominator accordingly (document).
- Sorting ties: stable tie-break by player_id for determinism.

TESTS (PHPUnit)
Add tests for:
- Elo update correctness (simple known scenario).
- False leader detection logic on synthetic dataset.
- Window slicing and ordering.
- ReportWriter: JSON has required keys; Markdown contains expected headings.

DELIVERABLES
- Symfony command + services + repositories + models.
- Example console output snippet in README (or inline).
- Example report.md structure.
- Production-ready code: strict_types, typed properties, PHPDoc, clear naming.

Now implement it.
