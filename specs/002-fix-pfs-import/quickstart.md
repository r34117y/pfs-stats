# Quickstart: walidacja i uzupełnienie importu PFS

Ten dokument opisuje zaimplementowaną procedurę importu i odbioru; kontrakt: [cli-import.md](contracts/cli-import.md).

## 1. Prerequisites

- Uruchomione usługi Compose `php`, `postgres`, `redis`; użyć PHP kontenera. Kod i dependencies zainstalowane zgodnie z projektem.
- Osobna, odtwarzalna kopia danych do akceptacyjnego uzupełnienia. Lokalnie przygotowano `pfs_acceptance` z kopii `var/pfs-import/before-import.dump`; schema-only baza `pfs_import_test` jest przeznaczona wyłącznie dla testów. Zapisujące testy integracyjne mają osobny DSN `PFS_IMPORT_TEST_DATABASE_URL` i muszą odmówić uruchomienia na bazie dev/produkcyjnej; nie czyścić istniejącej bazy aplikacji na potrzeby fixture.
- Dostęp do PFS dla niekompletnych źródeł, zapis do `tournament_html` i `var/pfs-import`. Brak sekretów w raportach.
- Dane początkowe kończą się Krakowem 2026-07-18; w środowisku planowania lokalne ID wynosiło 12136, ale uruchomienie odczytuje je z własnej bazy.
- Po zmianie kodu przebudować kontener Symfony dla używanego środowiska i trybu debug; stary skompilowany kontener może zachować wcześniejsze konstruktory komend. Po odtworzeniu kopii PostgreSQL wykonać `ANALYZE`. Dla komend danych wyłączyć Xdebug; lokalny wrapper PHP automatycznie włącza trigger dla `bin/console`, dlatego używać `/usr/local/bin/php-real -d xdebug.mode=off`.
- Każde polecenie poniżej wykonywać z katalogu głównego repozytorium. Dla produkcji najpierw standardowy deploy przez `bin/deploy-prod.sh`; ta instrukcja nie wykonuje wdrożenia.

```bash
docker compose ps
docker compose exec -T php php -v
docker compose exec -T php php bin/console app:pfs:tournaments:import --help
```

## 2. Testy bez importowania danych akceptacyjnych

```bash
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=0 -e REDIS_URL=redis://redis:6379/14 php php bin/phpunit --filter 'PfsTournamentResultsParserTest|PfsTournamentCalendarParserTest|PfsTournamentPayloadFactoryTest|PfsTournamentImportMatcherTest|OldMethodCurrentRankingServicePostgresTest|ImportPfsTournamentCommandTest|TournamentRoundImportServiceTest'
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=0 -e REDIS_URL=redis://redis:6379/14 php php bin/phpunit tests/Integration
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=0 -e REDIS_URL=redis://redis:6379/14 php php bin/phpunit --filter 'RankingEndpointTest|OldRankingEndpointTest|ApiCacheHeadersTest|ApiColdCacheRegressionTest|RevertLatestTournamentImportCommandTest|DownloadPfsTournamentHtmlCommandTest'
```

Nowe testy archiwum, resolvera i preflight uruchomić wraz z powyższym zestawem zgodnie z ich docelowymi nazwami. Testy integracyjne otrzymują odrębny test DSN przez konfigurację środowiska, bez wypisywania credentiali w shellu. API testować na wskazanej kopii danych, nie traktować ich jako dowodu rollbacku.

Oczekiwane: brak failures, warnings i deprecations. Baseline planowania: 15 testów / 196 asercji, exit 1 przez dwa przestarzałe `setAccessible()` w teście rankingu; plan usuwa je przy rozszerzaniu testów. Nie jest to obecnie czysty wynik testów.

## 3. Preflight i manifest historii

```bash
docker compose exec -T php php bin/verify-pfs-import.php --year=2026 --from-pfsid=1419 --through-pfsid=1399 --mode=preflight --output=var/pfs-import
```

Oczekiwane:

- Lista wymaganych turniejów porównana z oficjalnym kalendarzem; zweryfikowany punkt zmiany metody i wcześniejszy stan początkowy, nie tylko ostatnie dwa lata.
- Kompletne archiwalne wyniki użyte lokalnie; puste pliki od Białegostoku odświeżone z PFS. Nie mylić niekompletnego źródła z odwołanym wydarzeniem.
- Zidentyfikowane i potwierdzone rzadkie aliasy. Zapisane konflikty nie mogą być przykryte statusem gotowości.
- Manifest i raport wskazują wymaganych poprzedników oraz gotowość pojedynczych importów. Turniej z częściowo zapisanymi danymi wymaga świadomej korekty; nie uruchamiać overwrite w ciemno.
- Osobno rozstrzygnięty plik Łomianek z 2026-10-10, którego nie ma w aktualnym kalendarzu, oraz każda inna rozbieżność list. Potwierdzić na PFS, czy wydarzenie zostało odwołane/przeniesione; jeśli jest zakończonym rankingowym turniejem, uaktualnić manifest i kolejność przed importem zależnych wydarzeń. Ten punkt jest kontrolą rzeczywistych danych, nie uprawnieniem do pomijania turnieju.

Manifest opisuje oczekiwane źródła, porządek i zweryfikowany stan bazowy, a nie zmienny kursor wykonania. Poprawne dopisanie przewidzianego turnieju nie unieważnia manifestu i nie wymaga ponownego uruchomienia weryfikatora. Przed każdym importem preflight odczytuje rzeczywisty stan wymaganych poprzedników i ponawia kontrolę pod blokadą organizacji; samo wcześniejsze potwierdzenie w pliku nie zastępuje tej kontroli. Zmiana źródłowego hasha, mapowania tożsamości, porządku albo korekta/usunięcie wcześniej potwierdzonych danych unieważnia odpowiedni fragment historii i jego zależnych następców: wymagany jest ponowny preflight weryfikatora przed kontynuacją. Rollback i no-op nie zmieniają manifestu; po awarii między commit a kolejnym wywołaniem stan postępu wynika z DB.

Wyjątek potwierdzonego debiutanta z równoczesnego turnieju: jeżeli oficjalne f/w pierwszego wydarzenia zawiera nową osobę uczestniczącą wyłącznie w drugim, pełne zwalidowane wyniki drugiego wydarzenia mogą stanowić dowód jej udziału i tożsamości, powiązany przez ID PFS i hash. Po wykluczeniu kolizji wolno utworzyć profil i powiązanie organizacji w transakcji pierwszego importu oraz zapisać oficjalne f/w. Nie wolno wtedy dopisywać udziału, partii, skalpów ani statystyk kariery drugiego wydarzenia do starego rankingu pierwszego. Drugi import używa tego samego profilu i dopiero wtedy uwzględnia jego rzeczywiste partie. Brak dowodu lub konflikt nadal blokuje import; sam identyczny ranking weekendowy nie dowodzi debiutu.

### Uzupełnienie potwierdzonych luk wcześniejszych niż Kraków

Audyt implementacji wykazał siedem brakujących rankingowych turniejów 2026. Starsze eliminacje MP są już zawarte w lokalnych 30-rundowych zawodach (1245 → 9385, 1298 → 9425, 1348 → 9467) i nie mogą zostać zaimportowane drugi raz. Wydarzenia nierankingowe wyłączane są według jawnego oznaczenia oficjalnego kalendarza. PFS 1424 (Łomianki) nie ma obecnie wpisu turnieju na PFS ani w kalendarzu; zapowiedź nie dowodzi zakończonych zawodów.

Po rozstrzygnięciu tożsamości wykonać preflight z `--include-history-repairs`. Ta opcja wyłącznie przygotowuje jawny plan źródeł; nie importuje danych. Powstaje także `var/pfs-import/history-repair-events.json`, zachowywany przy kolejnych kontrolach. Najpierw uruchamiać pojedynczo:

```bash
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1421 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1432 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1412 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1409 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1423 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1426 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1441 --year=2026 --no-cache-warmup
```

Następnie wykonać 12 wywołań poniżej. Po uzupełnieniu historii uruchomić `app:ranking:backfill-old-method-snapshots --changed-only`, aby skorygować wyłącznie rozbieżne historyczne n/u, po czym ponownie wygenerować manifest przez preflight z `--include-history-repairs`. Tryb `verify` sprawdza cały zapisany plan 19 wydarzeń, także siedem naprawionych wcześniejszych turniejów.

Próbę akceptacyjną wykonać na `pfs_acceptance`, z osobnym Redis 13: do **każdego** wywołania Compose w tej próbie dodać `-e DATABASE_URL=postgresql://app:app@postgres:5432/pfs_acceptance -e REDIS_URL=redis://redis:6379/13` (lokalne domyślne dane Compose; w innym środowisku użyć jego konfiguracji). Nie rozgrzewać cache bazy docelowej danymi kopii. Do testów zapisu przekazać osobny `PFS_IMPORT_TEST_DATABASE_URL` wskazujący `pfs_import_test`; sam `APP_ENV=test` nie izoluje bazy.

## 4. Kolejność pojedynczych importów

Lista według pobranego 2026-11-19 [kalendarza PFS](https://www.pfs.org.pl/kalendarz.php?rok=2026), po Krakowie, do eliminacji MP włącznie. Daty są datami początku. Każdą pozycję potwierdzić preflightem; zestaw dowodów może ujawnić konieczność korekty listy, co musi być jawnie odnotowane.

| Kolejność | Data | PFS ID | Turniej / miejsce |
|-----------|------|--------|-------------------|
| 1 | 2026-08-01 | 1418 | II Mistrzostwa Strzyżowa |
| 2 | 2026-08-22 | 1406 | IX Otwarte Mistrzostwa Gdańska |
| 3 | 2026-09-05 | 1411 | VII Otwarte Mistrzostwa Białegostoku |
| 4 | 2026-09-12 | 1405 | V Mistrzostwa Oświęcimia |
| 5 | 2026-09-12 | 1439 | II Turniej o Puchar Wójta Gminy Kunice |
| 6 | 2026-09-19 | 1414 | XX Mistrzostwa Jaworzna |
| 7 | 2026-09-26 | 1415 | VI Otwarte Mistrzostwa Lublina |
| 8 | 2026-10-03 | 1410 | XXVIII Mistrzostwa Warmii i Mazur, Nowe Miasto Lubawskie |
| 9 | 2026-10-17 | 1425 | XII Mistrzostwa Ziemi Wałeckiej |
| 10 | 2026-10-24 | 1442 | V Mistrzostwa Sandomierza |
| 11 | 2026-11-07 | 1451 | I Turniej Scrabble „Piętnastka”, Sosnowiec |
| 12 | 2026-11-14 | 1399 | XXXIII Mistrzostwa Polski — faza eliminacyjna, Płock |

Kolejność nowej pary tej samej daty jawnie ustalono jako Oświęcim, potem Kunice, zgodnie z pozycjami kalendarza i sekwencyjnym modelem zatwierdzonym przez użytkownika. Jeśli środowisko ma już te turnieje, zachować jego istniejącą kolejność w serwisie i sprawdzić ją przed wznowieniem. Stary ranking drugiego turnieju korzysta z wyniku pierwszego. Oficjalne f/w mogą być identyczne dla obu; to nie błąd importu.

Uruchamiać **pojedynczo**, sprawdzając po każdym wywołaniu wynik, liczby, ID bazy, źródło i stan invalidacji. Zatrzymać procedurę przy błędzie; nie uruchamiać kolejnych pozycji mimo braku zależności. Nie dodawać automatycznej pętli.

```bash
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1418 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1406 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1411 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1405 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1439 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1414 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1415 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1410 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1425 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1442 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1451 --year=2026 --no-cache-warmup
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1399 --year=2026
```

Wyniki/log każdego wywołania zachować w `var/pfs-import`. Przy wznowieniu zacząć od pierwszego nieukończonego turnieju. Dodatkowe powtórzenie ukończonego daje `unchanged`, 0 nowych wierszy i 0 pobrań strony wyników z kompletnym archiwum.

## 5. Odbiór, idempotencja i cache

```bash
docker compose exec -T php php bin/verify-pfs-import.php --year=2026 --from-pfsid=1419 --through-pfsid=1399 --mode=verify --output=var/pfs-import
docker compose exec -T php php bin/console app:pfs:tournaments:import --pfsid=1399 --year=2026 --no-cache-warmup
```

Pierwsze polecenie porównuje wszystkie wiersze f/w z PFS oraz n/u z niezależną referencją. Tryb `verify` dla każdego wydarzenia porównuje także metadane, pełny zestaw uczestników, przypisania do osób, klasyfikację i wszystkie jej wartości, rundy/fazy/stoły, przeciwników, wyniki punktowe oraz pauzy/walkowery z kompletnym źródłem. Porównuje partie logiczne z uwzględnieniem lustrzanego zapisu DB. Każda brakująca, nadmiarowa lub zmieniona pozycja daje różnicę ze wskazaniem pola i exit 1, nawet przy identycznych countach i poprawnych rankingach. Raport ma oddzielne wyniki dla danych turniejowych, f/w oraz n/u. Drugie daje `unchanged`; porównać przed/po dane turnieju, powiązania osób, partie, wyniki, rankingi, audyt i dataset version. Nie wystarcza porównanie samych countów. Najnowszy turniej musi być docelowym; liczba zaimportowanych późniejszych wydarzeń 0.

W badaniu bieżący parser odczytał stronę docelową jako 120 uczestników / 120 pozycji klasyfikacji / 891 wpisów rund / 639 wierszy obu oficjalnych list. To dane kontrolne z momentu pobrania, nie zamiennik weryfikacji źródła ani dowód, że każdy wpis rundy jest kwalifikowaną partią rankingową.

Porównać zimne i ciepłe odczyty poniższych istniejących endpointów przed/po imporcie, używając właściwego ID organizacji i lokalnego ID turnieju. Zachować publiczne nagłówki cache i nowe dane; nie stosować ID PFS jako `tournamentId`.

```text
/api/tournaments?org=<organization-id>
/api/ranking?org=<organization-id>&tournamentId=<local-tournament-id>
/api/old-rank?org=<organization-id>&tournamentId=<local-tournament-id>
/api/players?org=<organization-id>
```

Przy `committed-cache-error` dane są już zapisane. Zatrzymać sekwencję, wykonać naprawę, zweryfikować widoczność i dopiero wznowić; nie cofać/importować ponownie zatwierdzonego turnieju w celu naprawy cache.

```bash
docker compose exec -T php php bin/console app:cache:refresh-after-import --org=21
```

`21` jest potwierdzone tylko dla lokalnego środowiska planowania; w innym środowisku podać odczytane ID PFS. Opcjonalny warmup dodać po udanej invalidacji, nie traktować go jako zamiennika kontroli.

Dowód wznowienia obejmuje dwie izolowane bazy odtworzone z tego samego stanu początkowego i te same źródła/konfigurację. W A wykonać pełną sekwencję bez przerwy; w B zatrzymać proces po zatwierdzeniu Oświęcimia (PFS 1405), przed Kunicami (1439), a następnie wznowić od pierwszej nieukończonej pozycji. Zastosować również kontrolną przerwę po innym pełnym turnieju i test rollbacku. Porównać końcowe dane turniejów, osób/powiązań, partii, wyników, f/w/n/u i semantykę audytu, normalizując wyłącznie techniczne ID pomiędzy bazami, czasy audytu oraz token dataset version. Powiązania i porządek zdarzeń muszą pozostać równe. Oddzielny no-op w tej samej bazie wymaga dokładnej niezmienności także audytu i dataset version. Dowód zapisać z punktami przerwania i wynikiem porównania, zamiast utożsamiać wznowienie z ponownym importem ostatniego turnieju. Wyniki przechowywać w `var/pfs-import/resume-comparison.json`, z osobnym dowodem dokładnej niezmienności no-op w `var/pfs-import/retry-verification.json`.

## 6. Pomiary i dowody końcowe

Na tej samej kopii danych, z wyłączonym Xdebug, zapisać czas odczytu źródła, resolvera, rekonstrukcji, transakcji i invalidacji oraz liczbę zapytań i peak memory. Rekonstrukcję porównać przed/po: pierwszy przebieg osobno, następnie mediana trzech ciepłych przebiegów bez zapisu danych. Wiersze wynikowe muszą być identyczne; liczba zapytań nie może rosnąć o dwa na każdy historyczny turniej po optymalizacji.

Raport końcowy zawiera źródła i hashe, zestaw manifestu, rozstrzygnięcia tożsamości i rozbieżności kalendarza, wyniki testów, porównanie wszystkich rankingów, no-op i wznowienie, widoczność API oraz pomiary. Błąd lub brak wymaganej historii pozostaje niezaliczonym warunkiem odbioru.

## Stan wykonania

Implementacja i testy techniczne są oddzielone od odbioru danych. Tożsamości rozstrzygnięto, wykonano próbę na dwóch kopiach i uzupełniono lokalną bazę aplikacji do PFS 1399. Zobacz `acceptance-report.md` po rzeczywiste wyniki i dowody. Preflight pozostaje obowiązkowy przy kolejnych importach.

Dla odrębnych kopii można wskazać `PFS_IMPORT_MANIFEST_PATH` na manifest wygenerowany dla tej bazy; domyślna ścieżka pozostaje `var/pfs-import/history-manifest.json`. Dane i wersje cache każdej kopii muszą być izolowane. Potwierdzone mapowania i odtwarzalna korekta lokalnych profili są w `config/pfs_player_aliases.yaml` i `identity-repair.sql`.
