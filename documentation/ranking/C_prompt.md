You are a senior PHP/Symfony engineer and data-oriented developer. Implement “Approach C”: estimate player strength with uncertainty (confidence intervals) and derive a minimum games threshold based on CI width / stability, using historical Scrabble match results.

GOAL
Build a Symfony Console Command that:
1) Fits a probabilistic strength model to head-to-head game outcomes in sliding 2-year windows.
2) Computes uncertainty per player (confidence interval width) at 95% / 99% levels.
3) Derives a “minimum games to be included in ranking” rule based on a threshold on CI width (or equivalently a required certainty).
4) Outputs polished CLI tables and generates a professional report (Markdown + JSON; optional HTML) that documents methodology, assumptions, results, and recommendations.

TECH CONTEXT
- Symfony 6/7, PHP 8.2+.
- Use Doctrine DBAL (preferred) or ORM (acceptable).
- Local compute only. No external services.
- Dataset: ~300k games, many years.
- Clean architecture: services + repositories + DTOs; heavy logic not inside Command.
- Provide good performance and progress indicators.
- Deterministic results (seed for any randomness).

ASSUMED DATA (ADAPTABLE)
Database contains at least:
- games:
    - id (int)
    - played_at (datetime UTC)
    - tournament_id (int, nullable)
    - player_a_id (int)
    - player_b_id (int)
    - result_a (float)  // 1=win, 0=loss, 0.5=draw if exists; else 0/1
      Optional:
    - round_no, points_a, points_b
      We focus on win/loss (and draw if present). If schema differs, add a mapping layer.

WINDOWS
A “window” is a 2-year interval [windowStart, windowEnd], inclusive.
Analyze sliding windows over history:
- window size: P2Y (default; configurable)
- step: P1M (default; configurable)
  For each window, consider only games inside it.

MODEL (CORE): Bradley–Terry / Logistic skill model
For each player i, latent skill s_i.
For a match i vs j:
P(i wins) = sigmoid(s_i - s_j)
If draws exist, support either:
- Treat draw as 0.5 outcome in Bernoulli likelihood approximation (acceptable for this tool),
  OR
- Provide optional Davidson extension for draws (bonus, only if feasible).

Estimation approach (choose one; implement well):
Option A (Recommended): L2-regularized maximum likelihood (MAP) with Newton / IRLS
- Add Gaussian prior: s_i ~ N(0, sigma_prior^2) for identifiability and stability.
- Optimize skills per window using iterative reweighted least squares (IRLS) / Newton-Raphson.
- This yields Hessian (Fisher information) → approximate covariance for s.
- Compute CI for each player: s_i ± z * sqrt(var_i).
  Option B: Bayesian Laplace approximation (equivalent to A).

IMPORTANT: identifiability
- Fix one reference player skill to 0 OR constrain mean(s)=0 via prior; document chosen method.
- Use regularization to avoid divergence for undefeated players in small samples.

UNCERTAINTY & CONFIDENCE INTERVALS
- For each player i in a window, compute:
    - estimate s_hat_i
    - standard error se_i = sqrt(Var(s_i)) from inverse Hessian (diagonal)
    - CI level: 95% (z=1.96), 99% (z=2.576)
    - CI width: width_i = 2 * z * se_i
      We will qualify a player for ranking if their uncertainty is small enough:
- width_i <= W_max
  OR equivalently se_i <= se_max
  We want to choose W_max (or se_max) so that inflation is minimized and players have stable estimates.

DERIVING A “MINIMUM GAMES THRESHOLD”
We want an algorithmic rule that can be expressed as either:
A) Uncertainty-based eligibility (preferred):
eligible if CI_width <= W_max
B) A simple “min games n_min” derived from data:
choose n_min such that, for example, 95% of players with >= n_min games have CI_width <= W_max
The command should output both:
- recommended W_max at 95% / 99% confidence levels
- and the implied n_min distribution (median/p75/p90 games to reach it)

CALIBRATION OF W_max (DATA-DRIVEN)
We need a sensible W_max chosen from historical data to reduce “lucky short streaks”.
Implement calibration using one of these objective criteria (implement at least one, make it configurable):
Criterion 1 (Recommended): False-Top stability criterion
- Define Top-K (default 50).
- For each window:
    - Compute skills using ALL games in window for players with >= stable_n games (default 120) as a proxy “stable” ranking.
    - For early sample sizes (e.g., n_early=35), compute skills using only first n_early games per player.
    - Mark “inflated top” events: early Top-K but not stable Top-K (with delta rank or delta skill threshold).
- Choose W_max such that P(inflated_top | CI_width <= W_max) <= alpha (alpha=0.05 for 95%, 0.01 for 99%).
  Criterion 2: CI-width target by cross-validation within window
- Split each player's games into two halves (by time); estimate s from first half, evaluate log-loss on second half.
- Choose W_max where predictive performance saturates; report curves.

Make calibration configurable, but ensure at least Criterion 1 is implemented end-to-end.

COMMAND API
Create command:
php bin/console pfs:rank:calibrate-ci

Options:
--start=YYYY-MM-DD (default earliest game date)
--end=YYYY-MM-DD (default latest game date)
--window=P2Y (default P2Y)
--step=P1M (default P1M)
--top-k=50
--n-early=35 (CSV allowed, e.g., "30,35,40")
--stable-games=120
--alpha=0.05 (repeatable: --alpha=0.05 --alpha=0.01)
--ci=0.95 (repeatable: --ci=0.95 --ci=0.99)  // maps to z-values
--sigma-prior=2.0  // prior std dev; controls regularization
--max-iter=30
--tol=1e-6
--seed=1234
--w-grid="0.5,0.6,...,3.0" (auto-generate if not provided)
--min-games-for-player=5
--out-dir=var/reports/pfs-ci-calibration/
--format=md,json,html (default md,json)
--dry-run
--max-windows=0 (0=all)

OUTPUT REQUIREMENTS (CLI)
Use SymfonyStyle:
- Print configuration summary.
- Progress bar for window processing and model fitting.
- Print a calibration grid table:
  columns: W_max, inflated_prob, p95_window_inflated_prob, coverage, median_games_to_qualify, p90_games_to_qualify
- Print final recommendations for each alpha / ci-level.
- Print “worst windows” list with dates + inflated_prob.

REPORT REQUIREMENTS
Generate report files in out-dir with timestamp:
- report.md (professional narrative)
- report.json (machine-readable metrics, curves, window stats)
  Optional:
- report.html (simple Twig template rendering)

Report.md sections:
1) Executive summary:
    - recommended W_max for 95% and/or 99% CI
    - implied n_min (median/p75/p90) games
    - tradeoffs: coverage vs stability
2) Methodology:
    - window definition, step
    - model definition (Bradley–Terry logistic), identifiability, regularization (sigma_prior)
    - estimation algorithm (IRLS/Newton), convergence criteria
    - CI computation via Hessian inverse diagonal (Laplace approx)
    - definition of “inflated top” and calibration criterion
3) Results:
    - calibration grid, curves as arrays
    - sensitivity: n_early in {30,35,40} if provided; sigma_prior variations (optional)
    - distribution plots-as-data: histogram bins for games_to_qualify
4) Limitations and next steps:
    - independence assumptions, tournament clustering, draw modeling
    - possible upgrade: Davidson draws, hierarchical model, time-varying skills, bootstrap CIs

ARCHITECTURE / CLEAN CODE
- Domain:
    - SkillModelInterface
    - BradleyTerryModel: fit(WindowGames) -> SkillEstimates + CovarianceDiag
    - DTOs: SkillEstimate(playerId, sHat, se, ciLow, ciHigh, ciWidth, gamesCount)
    - Calibration DTOs: GridPoint, WindowMetrics, Recommendation
- Infrastructure:
    - GamesRepository: streaming queries by window (ordered by played_at,id)
    - ReportWriter: Markdown + JSON (+ HTML optional)
- Application:
    - CalibrationService orchestrates:
        - build windows
        - per window: build per-player game sequences (for n_early truncation and stable reference)
        - fit model (early and stable)
        - compute inflated events, coverage, games-to-qualify stats
        - aggregate metrics across windows and across grid

PERFORMANCE
- Use DBAL forward-only cursor where possible.
- Avoid loading all games at once for all history; process per window.
- Model fitting per window:
    - Number of active players might be large; optimize:
        - map playerId -> index
        - build sparse design from games list
        - compute gradient/Hessian efficiently using game-wise accumulation
    - For Hessian inversion:
        - Full matrix inversion may be expensive for thousands of players.
        - We only need diagonal of inverse Hessian.
        - Implement an efficient approximation:
            - Use conjugate gradient with Hutchinson estimator for diag(H^{-1}), OR
            - Use block-wise / sparse approximation, OR
            - If player count is manageable per window (< ~2000), allow full inversion with numeric stability checks.
    - Implement a strategy:
        - default: full inversion if N<=N_max (configurable, e.g. 1500)
        - fallback: approximate diagonal using iterative solver (document in report)
- Make this choice configurable and documented.

NUMERICAL STABILITY
- Use stable sigmoid implementation to avoid overflow.
- Clamp probabilities to [1e-12, 1-1e-12] when computing log-loss.
- Regularize Hessian with prior (adds 1/sigma_prior^2 to diagonal).

TESTS (PHPUnit)
- Verify model fitting on a tiny synthetic dataset where skills are known (approximate).
- Verify CI computation (se decreases with more games).
- Verify inflated-top detection logic.
- Verify ReportWriter outputs required keys/sections.

DELIVERABLES
- Symfony command, services, repositories, models, tests.
- Example console output snippet.
- Example report.md structure.
- Production-ready code: strict_types, typed properties, PHPDoc, clean naming.

IMPORTANT
- Be explicit that this tool calibrates an eligibility criterion based on uncertainty, not necessarily replicating official PFS ranking points.
- If any assumption is needed, implement it as a configurable option and document it.

Now implement it.
