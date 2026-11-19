# Tasks: Naprawa i optymalizacja importu turniejów PFS

**Input**: Dokumenty z `specs/002-fix-pfs-import/`: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `contracts/cli-import.md`, `quickstart.md`.

**Prerequisites**: Przyjęty zakres A: istniejąca komenda importuje jeden turniej; przedział uzupełniamy kolejnymi wywołaniami. Równoczesne turnieje są liczone sekwencyjnie według dotychczasowej kolejności serwisu; drugi korzysta ze starego rankingu po pierwszym.

**Tests**: Testy zachowania parserów, rankingu, dopasowania i importu są wymagane przez konstytucję projektu oraz plan. Nowe przypadki regresji pisać przed odpowiadającą im zmianą i potwierdzić, że wykrywają problem; istniejące poprawne przypadki zachować. Nie uznawać atrap transakcji za dowód rollbacku PostgreSQL.

**Organization**: Fazy historii zachowują kolejność priorytetów ze specyfikacji. Każda ma własny kontrolowany scenariusz odbioru. Pełny import rzeczywistych danych jest zadaniem końcowym po integracji wszystkich historii, nie krokiem uruchamianym w trakcie tworzenia tej listy.

## Format: `[ID] [P?] [Story] Description`

Każde zadanie ma checkbox, kolejny identyfikator i ścieżkę. `[P]` oznacza możliwość równoległej pracy dopiero po ukończeniu wskazanych warunków fazy, w innych plikach; nie oznacza zgody na równoległe importowanie turniejów ani równoczesną edycję wspólnego pliku. `[US1]`–`[US5]` odpowiadają historiom w `spec.md`.

## Path Conventions

Ścieżki względem katalogu głównego repozytorium. Kod w `src/`, konfiguracja w `config/`, testy w `tests/`, narzędzia w `bin/`, źródła w `tournament_html/`, lokalne dowody wykonania w `var/pfs-import/`. Pliki nazwane poniżej, które jeszcze nie istnieją, należy utworzyć. Nie dodawać nowego frameworka ani importera zakresowego.

## Phase 1: Setup — stan wyjściowy i środowisko

**Purpose**: Ustalić powtarzalne środowisko testów i pomiarów bez naruszania danych aplikacji.

- [X] T001 Potwierdź bieżący diff, zachowaj istniejącą zmianę użytkownika w `src/Service/PfsTournamentResultsParser.php` oraz niepowiązane pliki; uruchom w kontenerze PHP bazowy zestaw z `specs/002-fix-pfs-import/quickstart.md` i zapisz runtime, wyniki oraz lokalny ostatni turniej w `var/pfs-import/baseline.md` bez importowania danych.
- [X] T002 [P] Przygotuj katalog reprezentatywnych źródeł i pochodzenia w `tests/Fixtures/pfs_tournament_html/provenance.json`: aktualny kalendarz 2026, kompletny turniej 15-rundowy, różne formaty historyczne, puste zapowiedzi, pauzy/remisy i nietypowe struktury; dla każdego zapisz URL/ID, hash i oczekiwane fakty sprawdzone w źródle, zachowując istniejące fixtures.
- [X] T003 [P] Przygotuj izolowany PostgreSQL dla zapisujących testów przez `tests/Integration/PfsImportDatabaseTestCase.php` oraz instrukcję w `specs/002-fix-pfs-import/quickstart.md`: wymagaj osobnego `PFS_IMPORT_TEST_DATABASE_URL`, blokuj połączenie do dev/produkcji, zakładaj minimalny zgodny schemat i czyść wyłącznie własne dane testowe.
- [X] T004 [P] Przygotuj odczytowy pomiar w `bin/benchmark-pfs-ranking.php` i zapisz baseline w `var/pfs-import/performance-before.json`: ta sama historia/turniej, Xdebug wyłączony, czas faz, query count, peak memory, osobno zimny przebieg i mediana trzech ciepłych; narzędzie nie zapisuje rankingów ani turniejów.

**Checkpoint**: Znamy stan początkowy, źródła, bezpieczny DSN testów i mierzalny baseline. T002–T004 można wykonywać po T001 w osobnych plikach; edycję quickstarta scalić przed kolejną fazą.

## Phase 2: Foundational — wspólna walidacja i kontrakty wewnętrzne

**Purpose**: Wspólna podstawa dla niezależnych historii, bez tworzenia kompletnego importera przed rozpoczęciem historii.

- [X] T005 Dodaj testy spójności źródła w `tests/Service/PfsTournamentSourceValidatorTest.php`: znaczący nierozpoznany wiersz, brak sekcji, niezgodność klasyfikacji/HH/rund, sprzeczne wyniki lustrzane, poprawny brak partii u części osób i poprawna pusta poczekalnia; użyj zweryfikowanych fixtures z T002. Dodaj wiersz danych celowo pomijany przez parser mimo pozostałych poprawnych DTO i sprawdź zgłoszenie oryginalnej sekcji, wiersza oraz tekstu.
- [X] T006 [P] Zdefiniuj typowane opisy źródła i wyniku kontroli w `src/PfsTournamentImport/PfsTournamentSource.php`, `src/PfsTournamentImport/PfsTournamentPreflightResult.php` i `src/PfsTournamentImport/PfsTournamentImportOutcome.php`: osobne ID PFS/lokalne, źródło/hash, liczby, diagnostyka oraz imported/unchanged/blocked/failed/committed-cache-error bez nowej tabeli workflow. Zdefiniuj także `src/PfsTournamentImport/PfsTournamentParseDiagnostics.php` dla surowych sekcji i klasyfikacji każdego wiersza z lokalizacją; zachowaj kompatybilność dotychczasowego wyniku parsera.
- [X] T007 Zaimplementuj `src/Service/PfsTournamentSourceValidator.php` na istniejących DTO parsera oraz surowych sekcjach/diagnostyce T006: rozpoznaj nagłówki i separatory, odrzuć nierozpoznany znaczący wiersz nawet przy spójnych pozostałych DTO, a następnie zweryfikuj tożsamość wydarzenia, kompletność i zgodność zestawów osób, wyników, rund i podsumowań, raportuj konkretny fragment źródła; już w tym zadaniu udostępnij surowe sekcje/diagnostykę przez `src/Service/PfsTournamentResultsParser.php` bez zmiany dotychczasowych reguł odczytu, aby T007 nie zależało od późniejszego T032. Nie zakładaj 12 rund ani pełnej liczby partii dla każdego uczestnika (po T005–T006).
- [X] T008 Usuń zbędne `ReflectionMethod::setAccessible()` w `tests/Service/OldMethodCurrentRankingServicePostgresTest.php` i potwierdź czysty bazowy wynik pod PHP 8.5 bez osłabiania `failOnDeprecation` w `phpunit.dist.xml`.
- [X] T009 Zarejestruj wspólne zależności i konfigurowalne lokalizacje archiwum, manifestu historii oraz mapowań w `config/services.yaml`, z domyślnymi ścieżkami w projekcie; nie dodawaj opcji automatycznego importu przedziału ani sekretów w konfiguracji.

**Checkpoint**: T005–T009 ukończone; kod historii może korzystać ze zwalidowanego źródła i wspólnych wyników. T006 jest równoległe do T005, a implementacja walidatora czeka na oba.

## Phase 3: User Story 1 — Uzupełnienie zakończonych turniejów (Priority: P1)

**Goal**: Istniejąca komenda poprawnie importuje wskazany zakończony turniej i pozwala wykonać udokumentowaną sekwencję po datach, bez traktowania ID PFS jako lokalnej chronologii.

**Independent Test**: Na izolowanej bazie z poprawną historią poprzedników i jednoznacznymi osobami zaimportować pojedynczy fixture, porównać osoby, partie i klasyfikację ze źródłem; odrzucić przyszłą zapowiedź i brak poprzednika. Ten odbiór nie zastępuje końcowej kontroli n/u ani importu całego rzeczywistego przedziału.

### Tests

- [X] T010 [P] [US1] Rozszerz `tests/Command/TournamentImport/ImportPfsTournamentCommandTest.php` o kontrakt pojedynczego importu, rozdział ID PFS/lokalnego, ścisłe dodatnie argumenty bez rzutowania `1399abc`, błędny rok, pustą zapowiedź i błąd źródła; sprawdzaj brak zapisów przy odmowie.
- [X] T011 [P] [US1] Dodaj `tests/Integration/PfsTournamentImportTransactionTest.php` z rzeczywistą bazą T003: poprawny zapis oraz błąd obliczeń po utworzeniu danych; rollback musi objąć osoby/powiązania, turniej, wyniki, lustrzane partie, f/w/n/u i audyt, bez zależności od kolejnych numerów sekwencji.

### Implementation

- [X] T012 [US1] Zweryfikuj i popraw tylko wykazane przypadki odczytu kalendarza w `src/Service/PfsTournamentCalendarParser.php` i `tests/Service/PfsTournamentCalendarParserTest.php`: aktualny 2026, granica miesięcy/roku, jeden dzień, identyfikatory niechronologiczne i jawny porządek tej samej daty; zachowaj poprawne daty start/koniec.
- [X] T013 [US1] Zaimplementuj odczytowy tryb `preflight` w `bin/verify-pfs-import.php` z logiką w `src/Service/PfsImportVerification/HistoryManifestVerifier.php`: źródłowe granice wydarzeń, historia od zweryfikowanego rozdziału metod i wcześniejszy stan kariery, porównanie z DB, manifest wersjonowany/hashowany, jawne wyłączenia, brak credentiali; tryb nie zapisuje danych aplikacji w DB; dodaj `tests/Service/PfsImportHistoryManifestVerifierTest.php` dla braków historii, nieaktualnych hashów, potwierdzonych wyłączeń i granic niechronologicznych ID. Manifest zapisuje oczekiwania i zweryfikowany stan bazowy bez zmiennego kursora; zwykłe dopisanie przewidzianego turnieju zachowuje jego ważność, a korekta/usunięcie historii lub zmiana hashów, aliasów czy porządku wymaga ponownego potwierdzenia zależnego fragmentu.
- [X] T014 [US1] Zaimplementuj `src/Service/PfsTournamentImportPreflight.php` i `tests/Service/PfsTournamentImportPreflightTest.php`: powiązanie wydarzenia ze źródłem, kontrola aktualności manifestu, istnienia/zgodności poprzedników i stanu początkowego; blokuj brak historii i konflikt/niekompletny zastany rekord, nie blokując pustej poczekalni potwierdzonej regułami. Dodaj test dwóch kolejnych importów bez ponownego generowania manifestu, restartu po commit oraz unieważnienia po korekcie poprzednika/zmianie źródła; sprawdzaj rzeczywistych poprzedników w DB również pod blokadą, nie odświeżaj manifestu przy no-op/rollbacku.
- [X] T015 [US1] Zachowaj atomowość `src/Service/TournamentRoundImportService.php`, dodaj transakcyjną blokadę organizacji i ponowne sprawdzenie tożsamości/duplikatu pod blokadą, dopasowanie po `(organization_id,urlid)` oraz kontrolowany legacy fallback po metadanych; zachowaj dotychczasowy konflikt HTTP i nie dodawaj indeksu unikalnego bez audytu legacy.
- [X] T016 [US1] Podłącz walidator i preflight do pojedynczego wywołania w `src/Command/TournamentImport/ImportPfsTournamentCommand.php`, z jawną walidacją argumentów i diagnostyką; pobranie wykonuj przed transakcją, a brak historii/źródła nie może zakończyć się komunikatem sukcesu (po T012–T015).
- [X] T017 [US1] Doprecyzuj i zweryfikuj listę pojedynczych wywołań w `specs/002-fix-pfs-import/quickstart.md`: źródłowe ID 1418, 1406, 1411, 1405, 1439, 1414, 1415, 1410, 1425, 1442, 1451, 1399, granice dat, Oświęcim przed Kunicami dla nowych wpisów i zachowanie istniejącego porządku środowiska; dołącz przypadki T010–T011 do poleceń odbioru.

**Checkpoint**: US1 jest testowalna na poprawnej historii kontrolnej. Do rzeczywistego uzupełnienia potrzebne są także US2–US5 i końcowy audyt źródeł. Nie uruchamiać jeszcze sekwencji akceptacyjnej.

## Phase 4: User Story 2 — Wiarygodne rankingi po każdym turnieju (Priority: P1)

**Goal**: f/w odpowiada źródłu, a n/u odtwarza całą starą metodę wraz z przedturniejowym rankingiem, skalpami i historią kariery; optymalizacja nie zmienia wyników.

**Independent Test**: Dla ustalonego zestawu historii ręcznie zweryfikowane przykłady oraz niezależna referencja dają identyczne pozycje, wartości i podział n/u; zapytania nie przepuszczają przyszłych turniejów, a drugi turniej tej samej daty uwzględnia pierwszy.

### Tests

- [X] T018 [P] [US2] Rozszerz `tests/Service/OldMethodCurrentRankingServicePostgresTest.php` o niezależnie policzone przypadki 29/30 partii, 199/200/201, przekroczenie całym turniejem i zatrzymanie bez dobierania starszego mniejszego, dolną granicę dwóch lat/leap day, debiut/powrót, skalpy przy dużej różnicy i remis, kolejność dokładnego wyniku oraz zaokrąglenia.
- [X] T019 [P] [US2] Dodaj `tests/Integration/PfsOldRankingChronologyTest.php`: odwrócone lokalne ID względem dat, inny lokalny ID wydarzenia zmiany metody, brak przecieku przyszłej historii i sekwencyjne turnieje tej samej daty; test ma sprawdzać rzeczywiste zapytania PostgreSQL.
- [X] T020 [P] [US2] Przygotuj niezależne oczekiwane wyniki wraz z uzasadnieniem w `tests/Fixtures/pfs_ranking/reference-cases.json` i testy w `tests/Service/PfsOldRankingReferenceCalculatorTest.php`: pełna rekurencja starej metody, nie wybór ostatnich oficjalnych rankingów turniejowych ani wywołanie badanego serwisu.

### Implementation

- [X] T021 [US2] Zaimplementuj odczytową referencję w `src/Service/PfsImportVerification/OldRankingReferenceCalculator.php` na podstawie reguł i T020, oddzielnie od produkcyjnego algorytmu; sprawdzaj ręczne przypadki zanim użyjesz jej do porównania całej historii.
- [X] T022 [US2] Popraw granice rekonstrukcji i dobór stanu początkowego w `src/Service/OldMethodCurrentRanking/OldMethodCurrentRankingServicePostgres.php`: identyfikuj wydarzenie zmiany metody po zweryfikowanych metadanych, stosuj chronologię daty/porządku serwisu zamiast samych ID oraz zachowaj istniejący interfejs i backfill.
- [X] T023 [US2] Zweryfikuj kwalifikowanie pauz, walkowerów i deduplikację faz na źródłach w `tests/Fixtures/pfs_ranking/game-eligibility.json`; popraw wykazane rozbieżności w `src/Service/OldMethodCurrentRanking/OldMethodCurrentRankingServicePostgres.php` i odpowiednich przypadkach T018–T019, bez mechanicznego doliczania pauz lub łączenia partii różnych faz.
- [X] T024 [US2] Uczyń brak wymaganego starego snapshotu i brak kwalifikowanej historii jawnym błędem w `src/Service/TournamentRoundImportService.php` i `src/Service/OldMethodCurrentRanking/OldMethodCurrentRankingServicePostgres.php`; zabezpiecz dzielenie przez zero i dodaj przypadki w `tests/Service/TournamentRoundImportServiceTest.php` przy zachowaniu prawidłowej pustej poczekalni.
- [X] T025 [US2] Zastąp odczyty N+1 porcjowanym wczytywaniem historii i ogranicz sortowanie/materializację publicznych wierszy do celu w `src/Service/OldMethodCurrentRanking/OldMethodCurrentRankingServicePostgres.php`; zachowaj pełną rekurencję oraz strumieniowanie backfillu, porównaj wszystkie wiersze z T021 i zapisz pomiar w `var/pfs-import/performance-ranking.json`.
- [X] T026 [US2] Popraw wybór najnowszego/danego starego rankingu i jego sąsiadów według tej samej chronologii w `src/Service/OldMethodCurrentRanking/OldMethodCurrentRankingServicePostgres.php` oraz istniejących odczytach w `src/State/Provider/OldRankingProvider.php` i `src/State/Provider/RankingProvider.php` tylko tam, gdzie wykazano założenie ID=czas; rozszerz `tests/Api/OldRankingEndpointTest.php` i `tests/Api/RankingEndpointTest.php` bez zmiany formatu odpowiedzi.
- [X] T027 [US2] Dodaj tryb `verify` do `bin/verify-pfs-import.php` z usługą `src/Service/PfsImportVerification/RankingComparison.php`: porównanie f/w wiersz po wierszu ze źródłem i n/u z T021 dla każdego wydarzenia, różnice osób/pozycji/wartości i niezerowy exit przy niezgodności, bez zapisów DB i bez uznawania samych countów za dowód; pokryj oba tryby, exit status oraz raport różnic w `tests/Service/PfsImportVerificationTest.php`. Dołącz pełne porównanie metadanych, uczestników i ich profili, wszystkich wartości klasyfikacji oraz każdej logicznej partii (faza/runda/stół, przeciwnicy, punkty, pauzy/walkowery); w `tests/Service/PfsImportVerificationTest.php` zmień pojedynczy wynik lub osobę bez zmiany countów/rankingów i wymagaj exit 1 oraz diagnostyki pola.

**Checkpoint**: Reguły i pełna rekonstrukcja są zweryfikowane niezależnie; optymalizacja jest mierzona i nie zmienia żadnego wyniku. US2 można odebrać na kontrolnej historii bez importu całego przedziału.

## Phase 5: User Story 3 — Obsługa aktualnych i historycznych źródeł (Priority: P2)

**Goal**: Kompletny lokalny HTML jest używany ponownie, puste lub brakujące wyniki są odświeżane atomowo, a odmienny format nie powoduje cichej utraty danych.

**Independent Test**: Fixture lokalny kompletny daje 0 pobrań strony wyników; pusta zapowiedź zostaje zastąpiona kompletnymi wynikami; niedostępne/uszkodzone źródło zachowuje poprawną kopię i daje konkretny błąd. Historyczne i aktualne wyniki zachowują znaczenie.

### Tests

- [X] T028 [P] [US3] Dodaj `tests/Service/PfsTournamentArchiveTest.php`: odczyt legacy bez sidecara, tożsamość ID/dat/nazwy, kolizja nazw, brak/pusty plik, niezgodny hash, atomowa wymiana i błąd sieci/zapisu; sprawdzaj 0 result-page requests dla kompletnej kopii.
- [X] T029 [P] [US3] Rozszerz `tests/Service/PfsTournamentResultsParserTest.php` i `tests/Service/PfsTournamentPayloadFactoryTest.php` o aktualny MP 15-rundowy, różne historyczne sekcje i nazwiska/miasta z diakrytyką, pauzy/remisy/walkowery i reprezentatywne fazy; dodaj znaczący uszkodzony wiersz, który nie może zostać pominięty.

### Implementation

- [X] T030 [US3] Zaimplementuj współdzielone nazewnictwo, jednoznaczne odnajdywanie i odczyt/zapis w `src/Service/PfsTournamentArchive.php`: legacy filenames, `<filename>.meta.json` z ID/URL/UTC/SHA-256, walidacja T007 przed atomic rename i wykrywanie przerwanej pary HTML/metadane (po T028).
- [X] T031 [US3] Podepnij wspólne archiwum do `src/Command/TournamentImport/DownloadPfsTournamentHtmlCommand.php` i rozszerz `tests/Command/TournamentImport/DownloadPfsTournamentHtmlCommandTest.php`; zachowaj opcje downloadera i nie nadpisuj poprawnego pliku niekompletnym źródłem.
- [X] T032 [US3] Wprowadź wyłącznie poprawki potwierdzone T029 w `src/Service/PfsTournamentResultsParser.php` i `src/Service/PfsTournamentPayloadFactory.php`, zachowując zmianę użytkownika, dotychczasowe fallbacki i informację o faktycznej strukturze partii; nie spłaszczaj nieobsługiwanych faz po cichu. Udostępnij diagnostykę surowych sekcji i wszystkich wierszy T006–T007; nie próbuj wykrywać cichego pominięcia wyłącznie z końcowych DTO.
- [X] T033 [US3] Podłącz wybór kompletne-archiwum/pobranie/odświeżenie do `src/Command/TournamentImport/ImportPfsTournamentCommand.php`, `src/Service/PfsTournamentImportPreflight.php` i `bin/verify-pfs-import.php`; po zmianie hashów unieważniaj zależne potwierdzenia i wymagaj ponownego preflightu weryfikatora przed uznaniem manifestu za ważny; raportuj źródło i błąd zamiast automatycznego uznania pustych wyników za odwołane zawody.
- [X] T034 [US3] Uzupełnij aktualne HTML i metadane w `tournament_html/` według listy w quickstarcie, dodaj brakującą stronę Sosnowca, a status archiwalnej zapowiedzi Łomianek wyjaśnij na oficjalnym PFS; zapisz dowód w `var/pfs-import/source-audit.json`, popraw manifest/listę dla każdego potwierdzonego dodatkowego zakończonego turnieju przed celem, nie wykonując importów DB.
- [X] T035 [US3] Rozszerz `tests/Command/TournamentImport/ImportPfsTournamentCommandTest.php` o użycie archiwum bez pobrania, odświeżenie pustych sekcji, błąd tożsamości źródła i zachowanie kopii po awarii; uruchom cały istniejący korpus źródłowy i zanotuj zgodność w `var/pfs-import/source-validation.md`.

**Checkpoint**: Obsługa źródeł działa samodzielnie z kontrolowanym klientem HTTP, a rzeczywiste dane do uzupełnienia są kompletne i pochodzą z PFS. Żaden plik zapowiedzi nie jest traktowany jako zaimportowany turniej.

## Phase 6: User Story 4 — Ciągłość tożsamości zawodnika (Priority: P2)

**Goal**: Rozpoznać istniejącą osobę mimo potwierdzonej zmiany danych, poprawnie dodać debiutanta i jawnie zablokować nierozstrzygniętą tożsamość.

**Independent Test**: Zmiana miasta, alias nazwiska i debiutant trafiają do poprawnych profili, konflikt/identyczne nierozróżnialne nazwy oraz nieznana osoba tylko w rankingu dają diagnostykę bez zatwierdzonych zapisów.

### Tests

- [X] T036 [P] [US4] Dodaj `tests/Service/PfsPlayerIdentityResolverTest.php`: potwierdzona zmiana nazwiska/miasta, normalizacja, istniejące wyjątki i ukryte profile, debiutant, kolizja nazwy, błędny alias organizacji oraz nierozróżnialni imiennicy; podobieństwo ani miasto samo nie może uprawniać do scalenia.
- [X] T037 [P] [US4] Dodaj `tests/Integration/PfsPlayerIdentityImportTest.php`: ten sam profil w udziale i f/w/n/u, osoby nieuczestniczące w turnieju, nierozpoznana osoba główna/poczekalni z rollbackiem i ponowienie po zatwierdzonym mapowaniu; nie duplikuj transakcyjnego harnessu T003. Dodaj przypadek: nowa osoba gra tylko w drugim równoczesnym turnieju, ale jest na wspólnych oficjalnych f/w pierwszego; potwierdź utworzenie jednego profilu na podstawie zwalidowanego źródła drugiego, rollback pierwszego i użycie tego samego profilu w drugim, bez przedwczesnego udziału/partii/skalpów i bez wpływu na n/u pierwszego.

### Implementation

- [X] T038 [US4] Dodaj schemat i walidowane mapowania w `config/pfs_player_aliases.yaml`, przenosząc istniejące wyjątki bez ujawniania ukrytych nazw; wymagaj jednoznacznego profilu w danej organizacji i podstawy potwierdzenia, bez niezweryfikowanych ID kopiowanych między środowiskami.
- [X] T039 [US4] Rozszerz `src/Service/PlayerCatalogService.php` o indeksy dokładnej/znormalizowanej nazwy, wykrywanie kolizji i dostępny kontekst miasta; zaimplementuj `src/Service/PfsPlayerIdentityResolver.php` z pierwszeństwem potwierdzonych aliasów, bez automatycznego fuzzy merge (po T036 i T038).
- [X] T040 [US4] Podłącz wspólny resolver do `src/Service/TournamentRoundImportService.php` oraz `src/Service/PfsTournamentImportPreflight.php`: spójne przypisanie wszystkich list, kontrolowane tworzenie debiutanta, przekazanie miasta, jawny błąd zamiast dereferencji null dla poczekalni; zachowaj interfejs payloadu HTTP i ukryte profile. Dla tak potwierdzonego debiutanta z drugiego równoczesnego wydarzenia dopuść profil i oficjalne f/w przy pierwszym imporcie; rejestruj dowód ID PFS/hash, nie importuj drugiego turnieju ani jego historii i nadal blokuj brak dowodu lub konflikt.
- [X] T041 [US4] Dodaj diagnostykę nierozstrzygniętych osób do `bin/verify-pfs-import.php` i `src/Service/PfsImportVerification/HistoryManifestVerifier.php`: wskazuj źródło/listę i kandydatów bez sekretów, nie zapisuj DB, blokuj potwierdzenie gotowości do czasu rozstrzygnięcia. Weryfikator może oznaczyć nową osobę jako rozpoznanego debiutanta na podstawie pełnych zwalidowanych źródeł drugiego równoczesnego wydarzenia; manifest zawiera dowód, nie wymaga istnienia jej profilu przed pierwszym importem i sam go nie tworzy.
- [X] T042 [US4] Przeprowadź kontrolę osób w rzeczywistych źródłach przedziału, zapisz ustalenia w `var/pfs-import/identity-audit.json` i potwierdzone mapowania w `config/pfs_player_aliases.yaml`; przypadki bez wystarczających dowodów wyjaśnij z użytkownikiem, po czym ponów T037 i preflight bez samodzielnego zgadywania zmiany nazwiska.

**Checkpoint**: Zestaw kontrolny nie zawiera błędnych połączeń ani duplikatów; nierozstrzygnięte przypadki są widoczne i blokują zależne zapisy. Nie wymaga to nowego interfejsu zarządzania osobami.

## Phase 7: User Story 5 — Sprawne ponowienie i kontrola importu (Priority: P2)

**Goal**: Powtórzenie i wznowienie nie zmieniają poprawnych danych, raport odróżnia zapis od błędu, a wyniki są widoczne niezależnie od warmupu.

**Independent Test**: Na izolowanej bazie ponowić poprawny import, przerwać procedurę między turniejami i wznowić, zasymulować błąd cache po commit oraz odczytać API z pominiętym warmupem; porównać treść danych, nie tylko liczby.

### Tests

- [X] T043 [P] [US5] Dodaj `tests/Integration/PfsTournamentImportRetryTest.php`: no-op dla kompletnego ID PFS i legacy null `urlid`, blokada częściowego/konfliktowego rekordu, wznowienie po rollbacku, dwa równoległe wywołania blokowane na organizacji oraz niezmienność danych/audytu przy powtórzeniu. Dodaj test porównawczy dwóch baz od tego samego seed: nieprzerwana sekwencja oraz zatrzymanie po commit 1405/przed 1439 i wznowienie; porównuj pełne dane domenowe/powiązania i semantykę audytu, normalizuj jedynie techniczne ID, czasy audytu i token dataset version między bazami, nigdy treść wyników.
- [X] T044 [P] [US5] Dodaj `tests/Service/PfsImportCachePublicationTest.php`: `save=false` i wyjątek zapisu dataset version, synchroniczna invalidacja mimo `--no-cache-warmup`, brak invalidacji/no warmup przy no-op, przekazanie tej samej wersji i organizacji do warmupu oraz odróżnienie awarii przed i po commit.

### Implementation

- [X] T045 [US5] Dodaj porównanie kompletnego zastanego importu w `src/Service/PfsImportVerification/ImportedTournamentComparator.php` i użyj go w `src/Service/PfsTournamentImportPreflight.php`: porównuj osoby, wyniki i partie ze źródłem, f/w oraz oczekiwane n/u, wykrywaj konflikty i pomijaj zapis przy pełnej zgodności, także dla potwierdzonych rekordów legacy.
- [X] T046 [US5] Wdróż wynik `unchanged` bez audytu i mutacji w `src/Command/TournamentImport/ImportPfsTournamentCommand.php` i granicy wywołania `src/Service/TournamentRoundImportService.php`; powtórz kontrolę pod blokadą i zachowaj konflikt dla istniejącej ścieżki HTTP, nie dopisuj `urlid` przy no-op (po T043 i T045).
- [X] T047 [US5] Sprawdzaj powodzenie `save()` i zgłaszaj nieudaną invalidację w `src/Service/DatasetVersionService.php`; w `src/Service/RefreshCacheAfterImportService.php` dodaj użycie już opublikowanej wersji bez drugiego bumpu przy zachowaniu dotychczasowego domyślnego działania innych wywołań (po T044).
- [X] T048 [US5] Dostosuj `src/Service/RefreshCacheAfterImportLauncher.php` i `src/Command/RefreshCacheAfterImportCommand.php` do opcjonalnego warmupu na ustalonej wersji/organizacji; obsłuż wynik uruchomienia, a istniejące wywołania z rollbacku i innych importów pozostaw kompatybilne.
- [X] T049 [US5] Rozdziel commit, synchroniczną invalidację i warmup w `src/Command/TournamentImport/ImportPfsTournamentCommand.php`; raportuj imported/unchanged/blocked/failed/committed-cache-error, exit 0/1/2, ID źródła/bazy, liczbę nowych i istniejących wierszy f/w/n/u oraz instrukcję naprawy cache bez sugerowania rollbacku po commit.
- [X] T050 [US5] Rozszerz `tests/Command/TournamentImport/ImportPfsTournamentCommandTest.php` o wszystkie wyniki kontraktu i zerowe pobrania/zapisy no-op; dodaj regresje dotychczasowych wywołań launchera w `tests/Command/TournamentImport/RevertLatestTournamentImportCommandTest.php` i pozostałych istniejących testach jego konsumentów.
- [X] T051 [US5] Rozszerz `tests/Api/ApiCacheHeadersTest.php`, `tests/Api/ApiColdCacheRegressionTest.php`, `tests/Api/RankingEndpointTest.php` i `tests/Api/OldRankingEndpointTest.php` o widoczność zmienionego datasetu i poprawne nagłówki zimnego/ciepłego odczytu bez warmupu; nie zmieniaj kontraktów ani prywatności odpowiedzi użytkownika.
- [X] T052 [US5] Uzupełnij `specs/002-fix-pfs-import/quickstart.md` o potwierdzone wznowienie, zapis logów, no-op, naprawę committed-cache-error i opcjonalny warmup; sprawdź wszystkie wymienione polecenia i zachowanie domyślnych opcji względem `specs/002-fix-pfs-import/contracts/cli-import.md`. Opisz ważność manifestu po zwykłym dopisaniu, konieczność ponownego preflightu po korekcie i kontrolowane porównanie przerwanego/pełnego przebiegu z dokładną listą dopuszczalnych różnic technicznych.

**Checkpoint**: Ponowienie jest deterministyczne i bez zapisów, awaria cache nie jest mylona z rollbackiem, a nowy dataset jest widoczny dla użytkownika. Pełna integracja US1–US5 jest gotowa do odbioru danych.

## Phase 8: Polish & Cross-Cutting Concerns — walidacja i rzeczywiste uzupełnienie

**Purpose**: Dokończyć testy, wykonać uzgodnioną procedurę i udokumentować rezultat wymagany przez użytkownika. Zależność: wszystkie historie ukończone; uruchomienie produkcyjne nie jest domyślnym celem tych zadań.

- [X] T053 Uruchom wąskie testy wszystkich zmienionych serwisów/komend, następnie integracyjne PostgreSQL i odpowiednie API zgodnie z `specs/002-fix-pfs-import/quickstart.md`; zapisz komendy i wyniki w `var/pfs-import/test-results.md`, usuń wykazane regresje bez wyłączania ostrzeżeń i kontroli cache.
- [X] T054 [P] Zaktualizuj `documentation/General.md` o pojedynczy import, wspólne archiwum, potwierdzone aliasy, sekwencyjne zdarzenia tej samej daty, nowy weryfikator i znaczenie `--no-cache-warmup`; opisz standardowy deploy przez istniejący `bin/deploy-prod.sh`, bez wykonywania wdrożenia.
- [X] T055 Odtwórz i zweryfikuj oddzielną kopię danych akceptacyjnych, kończącą się Krakowem, oraz aktualny manifest po audycie źródeł/tożsamości; zapisz identyfikację środowiska, kompletność wymaganej historii i wszelkie niezbędne potwierdzone korekty historyczne w `var/pfs-import/acceptance-preflight.json`, bez globalnego reimportu i bez zmiany poprawnych danych.
- [X] T056 Wykonaj na kopii akceptacyjnej kolejne pojedyncze importy z `specs/002-fix-pfs-import/quickstart.md` do PFS 1399 włącznie, sprawdzając i zachowując wynik każdego wywołania w `var/pfs-import/import-results/`; zatrzymuj się na błędzie, rozstrzygaj wykazane braki i wznawiaj od pierwszego nieukończonego turnieju, bez importowania późniejszych wydarzeń.
- [X] T057 Uruchom pełne porównanie źródeł i niezależnych rankingów przez `bin/verify-pfs-import.php --mode=verify` z pozostałymi argumentami z quickstarta; zapisz wszystkie pozycje porównania w `var/pfs-import/ranking-verification.json`, wymagaj 100% zgodności f/w/n/u dla każdego importowanego turnieju i najnowszego wydarzenia 1399. Oprócz rankingów wymagaj 100% zgodności pełnych zestawów uczestników, metadanych, klasyfikacji i wszystkich partii, wykrywając także błędne wartości przy niezmienionych countach; raport rozdziela dane turniejowe, oficjalne f/w i obliczone n/u.
- [X] T058 Sprawdź kontrolowane wznowienie na drugiej kopii od tego samego stanu początkowego co T056: zatrzymaj po commit Oświęcimia 1405/przed Kunicami 1439 oraz osobno po innym turnieju, wznów pojedyncze wywołania i porównaj pełne dane domenowe, powiązania i semantykę audytu z przebiegiem nieprzerwanym; normalizuj jedynie techniczne ID, czasy audytu i token dataset version pomiędzy kopiami, a wynik zapisz w `var/pfs-import/resume-comparison.json`. Następnie sprawdź no-op w tej samej bazie: ponów import ukończonego turnieju, porównaj dokładną treść danych/audytu i dataset version przed/po oraz liczbę żądań źródłowych; wynik 0 duplikatów, 0 mutacji i 0 ponownych pobrań kompletnych wyników zapisz w `var/pfs-import/retry-verification.json`.
- [X] T059 Uruchom `bin/benchmark-pfs-ranking.php` dla tej samej historii i parametrów co T004 i zapisz `var/pfs-import/performance-after.json`; porównaj medianę, query count i peak memory, potwierdź identyczne wiersze i brak N+1; popraw wykazane pogorszenie przed odbiorem, nie deklaruj niezmierzonego przyspieszenia.
- [X] T060 Zweryfikuj w środowisku akceptacyjnym zimne i ciepłe odczyty turniejów, obu rankingów i profili z `specs/002-fix-pfs-import/quickstart.md`, korzystając z lokalnego ID turnieju; zapisz widoczność nowych danych, wersję datasetu oraz nagłówki HTTP w `var/pfs-import/api-verification.json`, bez ręcznego globalnego czyszczenia kluczy.
- [X] T061 Po udanej próbie akceptacyjnej potwierdź tożsamość docelowej bazy użytkownika według bieżącej konfiguracji środowiska, zachowaj jej odtwarzalną kopię, wykonaj preflight i tę samą sekwencję pojedynczych importów do PFS 1399, następnie powtórz weryfikację wszystkich rankingów, retry i widoczności API; zapisz rzeczywiste wyniki w `var/pfs-import/target-results/`. Nie zastępuj realizacji instrukcją dla użytkownika i nie przełączaj się samodzielnie na nieuzgodniony serwer produkcyjny.
- [X] T062 Sporządź końcowy raport w `specs/002-fix-pfs-import/acceptance-report.md`: środowisko wykonania, turnieje i ID, źródła/hashe, rozstrzygnięte rozbieżności/osoby, testy, porównania rankingów, retry/cache i pomiary; podaj czy docelowa baza użytkownika została uzupełniona, czy wyłącznie kopia. Jeżeli import był wykonany tylko na kopii, zapisz pozostający krok zastosowania sprawdzonej procedury w uzgodnionej bazie i nie uznawaj celu danych użytkownika za osiągnięty.

**Checkpoint**: Kod i zachowanie zweryfikowane, wynik danych jednoznacznie udokumentowany. Odbiór wymaga wszystkich wymaganych zakończonych turniejów do eliminacji MP, poprawnego starego rankingu oraz jawnego wskazania faktycznie zmienionego środowiska. Nie zamykać celu samego naprawienia kodu jako pełnego uzupełnienia bazy.

## Dependencies & Execution Order

### Phase dependencies

| Faza | Warunek rozpoczęcia | Wynik |
|------|---------------------|-------|
| Setup T001–T004 | Dokumenty projektu | Fixtures, osobny PostgreSQL i baseline |
| Foundational T005–T009 | Setup | Wspólne DTO, walidacja i konfiguracja |
| US1 T010–T017 | Foundational | Pojedynczy import i preflight na poprawnej historii |
| US2 T018–T027 | Foundational; T013–T016 do integracji weryfikatora/zapisu | Poprawna rekonstrukcja i niezależne porównanie |
| US3 T028–T035 | Foundational; T013–T016 do integracji archiwum | Zweryfikowane i uzupełnione źródła |
| US4 T036–T042 | Foundational; T013–T016 do integracji resolvera | Ciągłość tożsamości i jawne konflikty |
| US5 T043–T052 | US1–US4 | Idempotencja, raport i udostępnienie danych |
| Final T053–T062 | US1–US5 | Testy, uzupełnienie danych i raport odbioru |

Kolejność domyślnego wykonania jest rosnąca według T-ID. Historie P2 nie są pomijane przy odbiorze pełnego zadania. T056 wymaga udanych T053 i T055; T057–T058 wymagają T056, a T059–T060 mogą nastąpić niezależnie po ustaleniu niezmiennej historii; T061 wymaga udanej próby i wyników T053–T060; T062 wymaga również potwierdzenia rezultatu w docelowej bazie z T061.

### User story completion graph

```text
Setup -> Foundational -> US1
                      -> US2 core -> integrate with US1
                      -> US3 core -> integrate with US1
                      -> US4 core -> integrate with US1
US1 + US2 + US3 + US4 -> US5 -> tests + data preflight
                                 -> sequential imports
                                 -> verify rankings/retry/API/performance
                                 -> acceptance report
```

US2, US3 i US4 mają odrębne scenariusze i mogą być rozwijane niezależnie na fixtures. Ich końcowe podłączenie do `ImportPfsTournamentCommand.php`, `TournamentRoundImportService.php`, preflight i weryfikatora jest sekwencyjne, z jednym właścicielem pliku w danym momencie. Faktyczny import ma zależności historyczne, których nie usuwa niezależność testów.

### Within each story

- Najpierw test kontraktu/przypadku domenowego, następnie modele/usługi, na końcu integracja i ponowne wykonanie testów.
- Przed oznaczeniem zadania jako ukończonego uruchomić najmniejszy istotny zestaw; po zmianie współdzielonego serwisu także odpowiednie regresje innych ścieżek.
- Brak faz lub danych koniecznych do poprawnego wyniku jest błędem do rozwiązania, nie powodem spłaszczania struktury albo ukrycia niepełnego rankingu.
- Jeżeli potwierdzony format wymaga migracji schematu, najpierw zaktualizować `plan.md` i tę listę o konkretną migrację/regresję; obecny projekt nie zakłada migracji ani aktualizacji dependencies.

## Parallel Execution Examples

Wszystkie poniższe przykłady zakładają ukończenie Setup i Foundational. Nie oznaczają, że podczas generowania tej listy należy uruchamiać agentów lub implementację.

| Historia | Zadania możliwe równolegle | Granica integracji |
|----------|----------------------------|-------------------|
| US1 | T010 test CLI + T011 test transakcji, różne pliki | T012–T016 sekwencyjnie, potem odbiór T017 |
| US2 | T018 reguły domenowe + T019 SQL/chronologia + T020 ręczna referencja | T021 po T020; T022–T026 serializować w pliku serwisu; T027 po T013 i T021–T026 |
| US3 | T028 kontrakt archiwum + T029 parser/payload | T030 po T028; T031–T033 po właściwych usługach, bez wspólnych edycji CLI |
| US4 | T036 resolver + T037 transakcyjne przypisania | T038–T040 po testach; T041 edytuje wspólny weryfikator osobno od innych historii |
| US5 | T043 retry/concurrency + T044 publikacja cache | T045–T050 integrują współdzielone usługi sekwencyjnie; T051/T052 po gotowej integracji |

Dodatkowe możliwości: T002–T004 po T001; T006 obok T005; T054 może być przygotowane równolegle z T053, a jego polecenia sprawdzone przed końcowym raportem. Nie modyfikować jednocześnie tej samej kopii danych podczas benchmarku i importowania.

## Requirement Traceability

| Wymaganie | Zadania realizujące i dowód |
|-----------|-----------------------------|
| FR-001 | T010, T016–T017, T055–T057 |
| FR-002 | T012–T014, T019, T022, T026, T034, T056 |
| FR-003 | T005, T007, T010, T028–T035 |
| FR-004 | T002, T005–T007, T029, T032, T035, T057 |
| FR-005 | T011, T023, T029, T032, T057 |
| FR-006 | T028, T030–T035, T058 |
| FR-007 | T002, T006, T013, T030, T034, T062 |
| FR-008 | T011, T027, T040, T057 |
| FR-009 | T018–T027, T057 |
| FR-010 | T013–T014, T019, T022, T024, T055 |
| FR-011 | T036–T040, T042 |
| FR-012 | T036–T042 |
| FR-013 | T011, T015, T043, T045–T046, T056, T058 |
| FR-014 | T001, T014–T015, T045–T046, T055–T058 |
| FR-015 | T006, T027, T041, T049–T050, T056, T062 |
| FR-016 | T025, T028, T030, T035, T043, T045–T046, T058–T059 |
| FR-017 | T044, T047–T051, T060 |

SC-001–SC-003 odbierają T056–T057; SC-004 i SC-007 T043/T058; SC-005 T036–T042; SC-006 T049–T050/T062; SC-008 T051/T060. Wszystkie pozostają wymagane dla pełnego odbioru; T061 powtarza kontrolę rezultatu w docelowej bazie użytkownika, a T062 dokumentuje faktyczny stan końcowy.

## Implementation Strategy

### MVP first

Minimalny przyrost techniczny: Setup + Foundational + US1 na kontrolowanej poprawnej historii. Pokazuje prawidłowy pojedynczy import bez automatyzacji przedziału. Minimalny wynik wartościowy zgodny z warunkiem użytkownika obejmuje także US2: bez poprawnego starego rankingu cel nie jest spełniony. Nie wdrażać niezweryfikowanego uzupełnienia na podstawie samego technicznego MVP.

### Incremental delivery

1. Wykonać fundament i odebrać US1 na fixtures/izolowanej bazie.
2. Zweryfikować reguły, chronologię i optymalizację US2 wobec niezależnej referencji.
3. Ukończyć obsługę archiwum US3 i tożsamości US4, bez rozszerzania zakresu o nowy importer wieloturniejowy.
4. Zintegrować idempotencję, raport i cache US5; przejść pełny zestaw istotnych testów.
5. Wykonać preflight i rzeczywistą procedurę pojedynczych importów, odebrać dane oraz zapisać raport ze wskazaniem środowiska.

### Completion discipline

Zadania pozostają nieoznaczone do czasu faktycznego wykonania. Nie wykonywać wdrożenia produkcyjnego w ramach samej generacji zadań. Brak dostępu do docelowej bazy nie uprawnia do twierdzenia, że została uzupełniona; raport oddziela gotowy kod, próbę akceptacyjną i rzeczywisty stan danych użytkownika.
