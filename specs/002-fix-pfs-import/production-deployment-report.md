# Wdrożenie produkcyjne PFS — 2026-11-19

Wdrożono zmiany na `ubuntu@54.38.54.56`, w `/var/www/pfs-stats`, z gałęzi `main`. Kod importu: commit `3d5b81c`; potwierdzone podczas serwerowego preflight dodatkowe mapowania: `c2dbe30`. Użyto `bin/deploy-prod.sh --no-migrate`: przebudowano obrazy i kontener/cache Symfony; zmiana nie zawiera migracji schematu. PHP serwera po przebudowie: **8.5.13**. Usługi PHP/nginx działają, PostgreSQL i Redis zachowały istniejące wolumeny.

## Wynik

Najnowszym turniejem jest **XXXIII Mistrzostwa Polski — faza eliminacyjna**, PFS **1399**, serwerowe ID **12143**. Dane: **120 uczestników, 880 kwalifikowanych partii, 891 logicznych wpisów rund**. Rankingi: **f=350, w=289, n=352, u=290**. Nie importowano późniejszych wydarzeń.

Zaimportowano 13 turniejów: Mszanę Dolną oraz dwanaście po Krakowie. Serwer miał już sześć pozostałych wydarzeń uzupełnianych lokalnie (PFS 1421, 1432, 1412, 1409, 1426, 1441), więc ich nie dublowano. Zachowano istniejące daty; cztery historyczne turnieje zapisano na serwerze z datą zakończenia. Manifest utworzono z rzeczywistej bazy serwerowej, której punktem początkowym był Kraków, ID 12130.

| PFS ID | Serwerowe ID | Data początku | Turniej |
|---|---|---|---|
| 1423 | 12131 | 2026-04-18 | I Mistrzostwa Mszany Dolnej w Scrabble |
| 1418 | 12132 | 2026-08-01 | II Mistrzostwa Strzyżowa w Scrabble pod patronatem Burmistrza Strzyżowa |
| 1406 | 12133 | 2026-08-22 | IX Otwarte Mistrzostwa Gdańska w Scrabble |
| 1411 | 12134 | 2026-09-05 | VII Otwarte Mistrzostwa Białegostoku o Puchar Rektora Uniwersytetu w Białymstoku |
| 1405 | 12135 | 2026-09-12 | V Mistrzostwa Oświęcimia w Scrabble |
| 1439 | 12136 | 2026-09-12 | II Turniej o Puchar Wójta Gminy Kunice |
| 1414 | 12137 | 2026-09-19 | XX Jubileuszowe Ogólnopolskie Mistrzostwa Jaworzna w Scrabble |
| 1415 | 12138 | 2026-09-26 | VI Otwarte Mistrzostwa Lublina w Scrabble |
| 1410 | 12139 | 2026-10-03 | XXVIII Mistrzostwa Warmii i Mazur w Scrabble o Puchar Burmistrza Nowego Miasta Lubawskiego |
| 1425 | 12140 | 2026-10-17 | XII Mistrzostwa Ziemi Wałeckiej w Scrabble |
| 1442 | 12141 | 2026-10-24 | V Mistrzostwa Sandomierza o Puchar Burmistrza |
| 1451 | 12142 | 2026-11-07 | I Turniej Scrabble "Piętnastka" |
| 1399 | 12143 | 2026-11-14 | XXXIII Mistrzostwa Polski - faza eliminacyjna |

## Kopia zapasowa i tożsamości

Przed zmianami danych utworzono pełny dump PostgreSQL w formacie custom: `/home/ubuntu/pfs-import-20261119/before-import.dump` (około 69 MB). Sprawdzono odczyt katalogu dumpa przez `pg_restore -l`. Katalog ma tryb 700, plik 600. SHA-256:

`72773785941d9c9565a7ff16336f9b9fe20c535abc074f8f0c847b3d9d6a3536`

Serwerowe ID profili sprawdzono przed wykonaniem transakcyjnej korekty. Połączono historie Michała Waszkiewicza pod ID 767, a profil 337 zaktualizowano do nazwiska Stasiak. Profil 5127 nie istniał na serwerze. Zastosowano potwierdzone aliasy Kupczyńskiej, Semenov i Grażyny Marii Nowak.

Preflight ujawnił dodatkowe warianty o jednoznacznym potwierdzeniu w wynikach:

- Jacek Jendrulok → Jacek Jędrulok (4304): ten sam debiut w Zabrzu 2025-06-22, 12 partii, 1202 skalpy, osiągnięty ranking 100,167.
- Iwona Skubis → Iwona Skubis-Galus (3442): ten sam występ w Katowicach 2025, 7 partii, 607 skalpów, osiągnięty ranking 86,7143.

Na serwerze użyto istniejących profili. Dwa lokalne profile wyłącznie rankingowe (5129 i 5122), istniejące już przed obecnym zadaniem, scalono z właściwymi osobami również w lokalnej bazie i obu kopiach akceptacyjnych. Zachowano wszystkie wiersze rankingów i nie zmieniono partii ani wyników turniejowych; skrypt odmawia działania przy kolizji lub profilu z partiami. Procedura lokalna: `local-ranking-identity-repair.sql`. Ponowne porównanie 19 lokalnych importów i kopii akceptacyjnych dało zero różnic.

## Weryfikacja

- Przed pushem i po dodaniu wariantów nazw: **69 testów, 669 asercji**, wynik poprawny. Produkcyjny `lint:container` przeszedł.
- Weryfikator porównał metadane, klasyfikację, uczestników, wszystkie partie i oficjalne f/w dla każdego z 13 importów; niezależny kalkulator porównał komplet n/u. Wynik: **zero różnic**. Raport zawiera wszystkie pozycje źródłowe, referencyjne i zapisane.
- `--changed-only` przeliczył 143 historyczne punkty rankingowe: skorygowano 130 rozbieżnych par n/u; 13 zgodnych pozostało bez zmian. Wstawiono 40920 wierszy n i 38950 wierszy u, usuwając 40918 wcześniejszych wierszy rozbieżnych zapisów.
- Ponowny import 1399 zwrócił `unchanged`. Dokładne odciski danych domenowych, profili, powiązań i audytu oraz dataset version są identyczne przed/po. Liczba pobrań strony wyników: **0**.
- Osiem ścieżek API sprawdzono na zimno i ciepło (16 odpowiedzi), w tym bieżące i wskazane rankingi, turnieje, lista osób i scalone profile; wszystkie poprawne.
- Publiczne HTTPS `https://pfs-stats.wordsontheboards.com/api/ranking?org=21` oraz `/api/old-rank?org=21` zwracają ID 12143, odpowiednio 350 i 352 osoby. Nagłówki publicznego cache i `s-maxage=86400` zachowane. Przed wdrożeniem API wskazywało Kraków, ID 12130.
- Warmup danych pomijano podczas importów; wersję datasetu publikowano po commit. Nie czyszczono globalnie Redis.

## Raporty

Na serwerze:

- `/home/ubuntu/pfs-import-20261119/`: dump, hash, log wdrożenia, korekty tożsamości, każdego z 13 importów, przeliczenia oraz weryfikacji;
- `/var/www/pfs-stats/var/pfs-import/`: manifest historii, hashe źródeł, pełne porównanie rankingów, dokładne odciski retry i raport API.

Kopie dowodów lokalnie: `var/pfs-import/production/server-evidence/` oraz publiczne odpowiedzi/nagłówki w `var/pfs-import/production/http-*.json` i `http-*.headers`. Bazy nie zastępowano lokalnym dumpem; importy wykonano na istniejących danych serwera.
