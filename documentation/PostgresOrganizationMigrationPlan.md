# Plan migracji MySQL -> Postgres (schema organizacyjny)

## Zakres
Migracja danych z tabel prefiksowanych organizacją (`{ORG}{SUFFIX}`) do wspólnego schematu Postgresa:
- `organization`
- `player` + `player_organization` (ManyToMany)
- `series`
- `tournament`
- `play_summary`
- `ranking`
- `tournament_result`
- `tournament_game`
- `text_resource`
- `game_record`

`SUFFIX`:
`PLAYER`, `PLAYSUMM`, `RANKING`, `SERTOUR`, `TOURHH`, `TOURS`, `TOURWYN`, `TRESOURCE`, `GCG`.

## Dlaczego są `legacy_*` kolumny
Identyfikatory w MySQL są lokalne dla organizacji i po scaleniu nie są jednoznaczne globalnie.
Dlatego schema zawiera tymczasowe kolumny (`legacy_id`, `legacy_player_id`, `legacy_tournament_id`, itd.), które:
- umożliwiają bezpieczne załadowanie danych bez natychmiastowego mapowania FK,
- pozwalają wykonać mapowanie relacji etapami,
- ułatwiają walidację danych po migracji.

## Kolejność migracji danych (ETL)
1. `organization`
- Wstaw 1 rekord per prefiks organizacji (`PFS`, `ANAGR`, ...).
- `code` = prefiks z nazwy tabeli.
- `name` = nazwa biznesowa (jeśli jest znana), tymczasowo można użyć `code`.

2. `player` i `player_organization`
- Załaduj graczy z każdej tabeli `{ORG}PLAYER` do `player`.
- Na etapie 1: bez deduplikacji między organizacjami (1 rekord legacy -> 1 rekord `player`).
- Wstaw relacje do `player_organization`.
- Zbuduj mapę: `(organization_id, legacy_player_id) -> player.id`.

3. `series`
- Załaduj `{ORG}SERTOUR` do `series` (`legacy_id = old.id`).
- Zbuduj mapę: `(organization_id, legacy_series_id) -> series.id`.

4. `tournament`
- Załaduj `{ORG}TOURS` do `tournament` (`legacy_id = old.id`).
- `legacy_winner_player_id = old.winner`, `legacy_series_id = old.sertour`.
- Zmapuj `winner_player_id` i `series_id` przez mapy legacy.
- Zbuduj mapę: `(organization_id, legacy_tournament_id) -> tournament.id`.

5. `play_summary`
- Załaduj `{ORG}PLAYSUMM` (`legacy_player_id = old.player`, `stype` itd.).
- Uzupełnij `player_id` przez mapę graczy.

6. `ranking`
- Załaduj `{ORG}RANKING` (`legacy_player_id`, `legacy_tournament_id`).
- Uzupełnij `player_id`, `tournament_id`.

7. `tournament_result`
- Załaduj `{ORG}TOURWYN` (`legacy_tournament_id`, `legacy_player_id`).
- Uzupełnij `player_id`, `tournament_id`.

8. `tournament_game`
- Załaduj `{ORG}TOURHH` (`legacy_tournament_id`, `legacy_player1_id`, `legacy_player2_id`).
- Uzupełnij `tournament_id`, `player1_id`, `player2_id`.

9. `text_resource`
- Załaduj `{ORG}TRESOURCE` (`resource_type = old.type`, `legacy_id = old.id`).

10. `game_record`
- Załaduj `PFSGCG` do `game_record` przypisując `organization_id = PFS`.
- Uzupełnij `tournament_id`, `player1_id` przez mapy.
- Docelowo analogiczna obsługa `GCG` dla innych organizacji.

## Walidacja po migracji
1. Porównanie liczności danych per organizacja i per typ tabeli.
2. Kontrola osieroconych relacji:
- rekordy z `legacy_* IS NOT NULL` i jednocześnie `*_id IS NULL`.
3. Kontrola unikalności i duplikatów pod nowe klucze.
4. Losowe porównania wyników endpointów/stats (MySQL vs Postgres).

## Cleanup po stabilizacji
Po potwierdzeniu poprawności migracji:
1. Dodać migrację usuwającą kolumny `legacy_*`.
2. Zaostrzyć ograniczenia (`NOT NULL`) tam, gdzie biznesowo wymagane.
3. Rozważyć deduplikację graczy między organizacjami (drugi etap, poza migracją 1:1).
