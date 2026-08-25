## General

The main goal of this project is presenting ranking and statistics related to Polish Scrabble Federation (PFS) and Polish Scrabble clubs. All entities are represented as "organization", where PFS is the main organization.

## PFS games

The main source of truth for PFS is the official website (https://www.pfs.org.pl/). Results are imported from https://www.pfs.org.pl/turniej.php?id={tournamentID} with console command (src/Command/TournamentImport/ImportPfsTournamentCommand.php). Website tournament ids represent the order of tournament registration, not the order of actual tournament dates. The results pages contain several tabs with tournament results, official ranking after tournament, etc. Usually there is one tournament per week (sometimes there are no tournaments and sometimes there are two tournaments). The most common tournament structure is 12 rounds played in 2 days with 7 rounds in the first day and 5 tournaments in the second day, but other structures also occur (one day tournaments with 7 rounds or different rounds distribution within two days or 13 rounds tournaments). Some tournaments have different structure:
- Puchar Polski has groups in the first stage and the dest players compete in a knockount system in the latter stage
- Mistrzosta Polski in the final stage has 16 players: 13 players advance from the eliminations tournaments and 3 players advance based on their results in the GrandPrix series during the whole season (some tournaments have the GrandPrix status)

Most tournaments are yearly events grouped in tournament series (Mistrzostwa Warszawy, Mistrzostwa Poznania, etc.) Usually there is one series per city, but for the biggest cities there can be more.

## PFS Ranking

The official ranking rules (in Polish) can be found in https://www.pfs.org.pl/ranking.php in the "Jak powstaje ranking tab". This page contains also the most recent ranking and the waiting list for players with <30 games played in the last 2 years.

This project maintains also the previous ranging (reffered as "Old ranking"), which had a bit differet rules. Instead of picking the best tournaments from the 2 years window, the last tournaments were picked (with the same rule that the total number of games can't exceed 200). The first tournament to apply the new ranking was "II Wielicki Turniej w Scrabble" - the ranking after this tournament is the first that differs from the old ranking.

There are 4 types of PFS ranking types:
- f - the main new ranking (also all the rankings from before the rules changed)
- w - the new ranking waiting list
- n - the main old ranking
- u - the old ranking waiting list

PFS tournament imports store official ranking rows from the tournament ranking
tab. Rows above the waiting-list heading are imported as `f`; rows below
`Poczekalnia na dzień` are imported as `w`. For tournaments from "II Wielicki
Turniej w Scrabble" onward, the import also calculates old-method snapshots:
main old-method rows are stored as `n`, and below-threshold old-method waiting
rows are stored as `u`. Reverting the latest PFS tournament import removes all
ranking rows for the reverted tournament, including `f`, `w`, `n`, and `u`.

## Club games

Club tournaments occur usually once a week and club admins upload the results manually (an account is required for that).

## Old Statisctics Website

This project is the succesor of the older statistics website https://scrabble.stats.org.pl/ which is still mantained and can be treated as an additional source of truth for verification.

## Cache

Due to the specific nature of this projects (rare updates, heavy computation related to statistics) caching strategy is essential for this project.

## Architecture

- This app uses Symfony with API Platform
- Regular controllers and twig templates are used to render page Skeletons
- Most data is loaded from API Endpoints using Alpine JS
- Bulma is used as a CSS framework
- Pages must look good on all devices (desktop, mobile)

## Rules

- Try to create and use reusable components, but be sure that you don't break anything while introducing DRY
- The goal is to fully cover the projects with tests - always make sure that tests pass and add or update tests when necessary
- Use the state-of-the-art modern PHP8:
    - make classes final and readonly whenever it is appropriate
    - make properties readonly whenever it is appropriate
    - always type hint everything that supports type-hinting whenever possible
- Type of files you should consider creating:
    - Symfony Controller (used only to render the basic twig template, no data is fetched within the controller)
    - Twig template (contains the HTML skeleton of the page and the relevant AlpineJS code)
    - ApiResource (represents a single endpoint)
    - Provider / Processor - according to ApiPlatform best practices
    - Console Command - use classes provided by Symfony
    - Service - the executor of business logic (should be usable by API Platform state management classes and console commands)
- You can use `doctrine:query:sql` for testing created SQL
- You can execute commands in docker containers to verify that your code works as intended
    - Containers for this project are prefixed with `scrabble-stats-api-`, for example `scrabble-stats-api-php-1`
    - Credentials can usually be found in `.env` or `.env.local`
