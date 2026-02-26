# Scrabble Stats API

Scrabble Stats API is a modern home for competitive Scrabble results and rankings.

## Purpose

This project exists to make Scrabble tournament data easier to explore, compare, and understand.
It replaces an older statistics website with a clearer, more complete experience for players, organizers, and fans.

## What This Project Provides

- A central place for rankings, player profiles, tournament results, and historical performance.
- Clear views of both individual progress and broader community trends.
- Useful statistics for tracking improvement, milestones, and long-term records.

## Who It Is For

- Players who want to track their own results and compare against others.
- Tournament organizers who need reliable historical context.
- Community members who want better visibility into the competitive scene.

## Project Goal

The long-term goal is to keep Scrabble competition history accessible, transparent, and useful, so the community can learn from past results and celebrate achievements more easily.

## Documentation

This README focuses on the project purpose.
Technical and implementation documentation lives in the `documentation/` directory.

Caching details are documented in `documentation/Caching.md`.

## Ranking Calibration Command

`pfs:rank:calibrate-rd` calibrates a Glicko-2 RD threshold against historical 2-year sliding windows.

Example:

```bash
php bin/console pfs:rank:calibrate-rd \
  --alpha=0.05 --alpha=0.01 \
  --early-games=35 --stable-games=120 \
  --k=50 --step=P1M --window=P2Y
```

Reports are written to `var/reports/pfs-rd-calibration/<timestamp>/`:

- `report.md`
- `report.json`
- `report.html` (optional via `--format=md,json,html`)
