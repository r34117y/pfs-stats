# Endpoints done

## Main ranking (landing page)

Endpoint: GET `/`
Type: Controller renders Twig + AlpineJS fetches table as ApiResource
Description: Table with current ranking

## Players List

Endpoint: GET `/players`
Type: Controller renders Twig + AlpineJS fetches table as ApiResource
Description: Table with all the PFS players

## Tournaments List

Endpoint: GET `/tournaments`
Type: Controller renders Twig + AlpineJS fetches table as ApiResource
Description: Table with all the PFS tournaments

## Player profile

Endpoint: GET `/players/{playerId}`
Type: Controller renders Twig + AlpineJS fetches data as ApiResource
Description: Player's landing page. Contains player's personal data (name, age photo, first and last tournament), and main statistics (current ranking and position, total games and tournaments played, total games won, total games and tournaments played won in last 12 months, total games and tournaments played won in the current year)

## Player tournaments

Endpoint: GET `/players/{playerId}/tournaments`
Type: Controller renders Twig + AlpineJS fetches table as ApiResource
Description: List of player's tournaments (tournaments that player participated in). Sorted by date, from the most recent. Columns: tournament name, tournament rank, number of players, final position, games won, games draw, games lost, average points, average points lost, average points sum, achieved rank, position as %

## Ranking History

Endpoint: GET `/players/{playerId}/rank-history`
Type: AlpineJS fetches data as ApiResource
Description: Two independent endpoints should be used:
- Milestones: milestone is the first time that player achieved "special" rank (110, 120, 130, 140 etc.). For each milestone fetch the date and tournament when this player achieved it for the first time. Start with 100 (this is the default rank so will always be player's first tournament).
- Rank History: Show player ranking as a chart from the first to the last played tournament

## Player records

Endpoint: GET `/players/{playerId}/records`
Type: Controller renders Twig + AlpineJS fetches tables as ApiResources
Description: Page aggregates several tables with player's records. Each table is a seperate endpoint handled by a seperate (independent) AlpineJS code and should have a dedicated Twig subtemplate.
Tables to fetch:

### Games with the most points

Endpoint: GET `/players/{playerId}/records/most-points`
Type: AlpineJS fetches table as ApiResource
Description: Fetch top games (described by limit, default 10) regarding points scored by player. Columns: #, Points, Opponent, Score, Tournament

### Games with the least points

Endpoint: GET `/players/{playerId}/records/least-points`
Type: AlpineJS fetches table as ApiResource
Description: Fetch bottom games (described by limit, default 10) regarding points scored by player. Columns: #, Points, Opponent, Score, Tournament

### Games with the highest sum of points

Endpoint: GET `/players/{playerId}/records/points-highest-sum`
Type: AlpineJS fetches table as ApiResource
Description: Fetch top games (described by limit, default 10) regarding the sum of points scored by both players. Columns: #, Points, Opponent, Score, Tournament

### Games with the lowest sum of points

Endpoint: GET `/players/{playerId}/records/points-lowest-sum`
Type: AlpineJS fetches table as ApiResource
Description: Fetch bottom games (described by limit, default 10) regarding the sum of points scored by both players. Columns: #, Points, Opponent, Score, Tournament

### Games with the most points scored by opponent

Endpoint: GET `/players/{playerId}/records/opponent-most-points`
Type: AlpineJS fetches table as ApiResource
Description: Fetch top games (described by limit, default 10) regarding the sum of points scored by opponent. Columns: #, Points, Opponent, Score, Tournament

### Games with the least points scored by opponent

Endpoint: GET `/players/{playerId}/records/opponent-least-points`
Type: AlpineJS fetches table as ApiResource
Description: Fetch bottom games (described by limit, default 10) regarding the sum of points scored by opponent. Columns: #, Points, Opponent, Score, Tournament

### Games with the highest advantage

Endpoint: GET `/players/{playerId}/records/highest-win`
Type: AlpineJS fetches table as ApiResource
Description: Fetch games (described by limit, default 10) where player won with the highest advantage over his opponent. Columns: #, Points, Opponent, Score, Tournament

### Games with the highest opponent advantage

Endpoint: GET `/players/{playerId}/records/highest-lose`
Type: AlpineJS fetches table as ApiResource
Description: Fetch games (described by limit, default 10) where opponent won with the highest advantage over the player. Columns: #, Points, Opponent, Score, Tournament

### Highest draw

Endpoint: GET `/players/{playerId}/records/highest-draw`
Type: AlpineJS fetches table as ApiResource
Description: Fetch drawn games (described by limit, default 10) sorted by points descending. Columns: #, Points, Opponent, Score, Tournament

### Lost Games with the most points

Endpoint: GET `/players/{playerId}/records/lost-with-most-points`
Type: AlpineJS fetches table as ApiResource
Description: Fetch games (described by limit, default 10) where player lost, sorted by scored points desc. Columns: #, Points, Opponent, Score, Tournament

### Won Games with the least points

Endpoint: GET `/players/{playerId}/records/won-with-least-points`
Type: AlpineJS fetches table as ApiResource
Description: Fetch games (described by limit, default 10) where player won, sorted by scored points asc. Columns: #, Points, Opponent, Score, Tournament

### Won Games with the most points by opponent

Endpoint: GET `/players/{playerId}/records/won-with-most-points-by-opponent`
Type: AlpineJS fetches table as ApiResource
Description: Fetch games (described by limit, default 10) where player won sorted by points scored by the opponent desc. Columns: #, Points, Opponent, Score, Tournament

### Lost Games with the least points by opponent

Endpoint: GET `/players/{playerId}/records/lost-with-least-points-by-opponent`
Type: AlpineJS fetches table as ApiResource
Description: Fetch games (described by limit, default 10) where player lost sorted by points scored by the opponent asc. Columns: #, Points, Opponent, Score, Tournament

### Longest winning streak

Endpoint: GET `/players/{playerId}/records/win-streak`
Type: AlpineJS fetches table as ApiResource
Description: Fetch streaks (described by limit, default 10) of games won by player. Columns: #, Streak, Tournaments (comma separated)

### Longest losing streak

Endpoint: GET `/players/{playerId}/records/lose-streak`
Type: AlpineJS fetches table as ApiResource
Description: Fetch streaks (described by limit, default 10) of games lost by player. Columns: #, Streak, Tournaments (comma separated)

### Longest streak with at least n points

Endpoint: GET `/players/{playerId}/records/streak-by-points?min=n`
Type: AlpineJS fetches table as ApiResource
Description: Fetch streaks (described by limit, default 10) of games where player scored at least n points. Columns: #, Streak, Tournaments (comma separated)

### Longest streak with sum at least n points

Endpoint: GET `/players/{playerId}/records/streak-by-sum?min=n`
Type: AlpineJS fetches table as ApiResource
Description: Fetch streaks (described by limit, default 10) of games where the sum of points scored by both players was at least n points. Columns: #, Streak, Tournaments (comma separated)

### Longest streak of wins against single player

Endpoint: GET `/players/{playerId}/records/win-streak-by-player`
Type: AlpineJS fetches table as ApiResource
Description: Fetch streaks (described by limit, default 10) of games where player won with a given player (it means a streak with any given player, not particular one). Columns: #, Streak, Opponent, First and last Tournament

### Longest streak of loses against single player

Endpoint: GET `/players/{playerId}/records/lose-streak-by-player`
Type: AlpineJS fetches table as ApiResource
Description: Fetch streaks (described by limit, default 10) of games where player lost with a given player (it means a streak with any given player, not particular one). Columns: #, Streak, Opponent, First and last Tournament

## Games Balance

Endpoint: GET `/players/{playerId}/game-balance`
Type: Controller renders Twig + AlpineJS fetches table as ApiResource
Description: Each row represents one opponent and summarizes all games played with that opponent. It shouuld be sorted by % of wins (desc) and total number of games played against that opponent (desc). Columns: #, Opponent, % of wins, Balance (won - lost), Balance small points (scored - lost), No of wins, No of draws, No of loses, Streak (eg. +3 or -2), Average points, Average opponent's points

## Tournament details

Endpoint: GET `/tournaments/{tournamentId}`
Type: Controller renders Twig + AlpineJS fetches data as ApiResource
Description: Tournament details page. It has a widget with basic tournament data (name, date, referee name, address). Below (separate request) it has a table with tournament results. The columns are: Position, Player, Rank before, No of wins, Total Points Scored, Diff (points scored - points lost), Sum of points (scored by player + scored by opponent), Scalp, Rank achieved (= Scalp / Number of Games), Avg Opponent Rank

## Player-Tournament Summary

Endpoint: GET `/tournaments/{tournamentId}/players/{playerId}`
Type: Controller renders Twig + AlpineJS fetches data as ApiResource
Description: This page consists of two widgets.
- First is box with data: Position, Rank achieved, Avg opponent rank, Avg points per game, Avg opponent's points per game, Avg points per game in won games, Avg opponent's points per game in won games, Avg points per game in lost games, Avg opponent's points per game in lost games, Avg sum of points, Avg diff in won games, Avg diff in lost games
- Second is table, each row represents a game. Columns are: Round, Table#, Was first to play?, Result (win, lose, draw), Opponent, Achieved Rank, Points, Points lost, Sum of points, Scalp
  There should be a link back to the tournament main page at the top of the page.

## Stats - Miejsca w Turniejach

Endpoint: GET `/stats/all-times-results`
Type: Controller renders Twig + AlpineJS fetches data as ApiResource
Description: This is a table where each row represents a player. It summarizes the all-time results of that player. The columns are: #, Player name (link to player profile), 1st (number of tournaments won), 2nd (number of tournaments where player was 2nd), 3rd, 4th, 5th, 6th, 1-3 (sum of 1st, 2nd, 3rd), 1-6 (sum of 1st to 6th). Table is sorted by first colum first, second second up to sixth.

## Stats - Podsumowanie

Endpoint: GET `/stats/all-time-summary`
Type: Controller renders Twig + AlpineJS fetches data as ApiResource
Description: Table with tournaments summary. It has 3 columns: Statistic name, Value (all times), Value (last 12 months). The statistics are:
- Liczba turniejów
- Suma uczestników turniejów
- Średni ranking turniejów
- Grających zawodników
- Na liście rankingowej
- Rozegrane gry
- Odsetek remisów
- Średnia ilość graczy na turnieju
- Gier na zawodnika
- Gier powyżej 350 punktów (%)
- Gier powyżej 400 punktów (%)
- Gier powyżej 500 punktów (%)
- Gier powyżej 600 punktów (%)
- Średnia punktów zwycięzcy
- Średnia punktów pokonanego
- Wygrane gracza powyżej 130 z graczem 110-130
- Wygrane gracza powyżej 130 z graczem poniżej 110
- Wygrane gracza 110-130 z graczem poniżej 110
- Odsetek gier wygranych przez gospodarza

## Stats - Liczba gier

Endpoint: GET `/stats/games`
Type: Controller renders Twig + AlpineJS fetches data as ApiResource
Description: This is a table where each row represents a player. It summarizes the number of games played by a player. Add item to side menu if not already exists. All column should be sortable. The colums are:
- #
- Zawodnik (link to player's profile)
- Gier (Total number of games)
- Ost 24 mies. (Games played in last 24 months)
- Ost 12 mies. (Games played in last 12 months)

## Stats - Wygrane gry

Endpoint: GET `/stats/games-won`
Type: Controller renders Twig + AlpineJS fetches data as ApiResource
Description: This is a table where each row represents a player. It summarizes the number of games won by a player. Add item to side menu if not already exists. All column should be sortable. The colums are:
- #
- Zawodnik (link to player's profile)
- Wygranych (Total number of won games)
- % (of games won)
- Ost 24 mies. (Games won in last 24 months)
- % (of Games won in last 24 months)
- Ost 12 mies. (Games won in last 12 months)
- % (of Games won in last 12 months)

## Stats - Liczba turniejów

Endpoint: GET `/stats/tournaments`
Type: Controller renders Twig + AlpineJS fetches data as ApiResource
Description: This is a table where each row represents a player. It summarizes the number of tournaments played by a player. Add item to side menu if not already exists. All column should be sortable. The colums are:
- #
- Zawodnik (link to player's profile)
- Turniejów (Total number of tournaments)
- Ost 24 mies. (tournaments played in last 24 months)
- Ost 12 mies. (tournaments played in last 12 months)

# Endpoints todo

## Stats - Avg Points Per Game

Endpoint: GET `/stats/tournaments`
Type: Controller renders Twig + AlpineJS fetches data as ApiResource
Description: This is a table where each row represents a player. It summarizes the average player score. Add item to side menu if not already exists. All column should be sortable. The colums are:
- #
- Zawodnik (link to player's profile)
- Średnia (Average score in all games)
- Ost 24 mies. (Average score played in last 24 months)
- Ost 12 mies. (Average score played in last 12 months)
