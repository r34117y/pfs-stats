# Research: import pojedynczego turnieju PFS

Data: 2026-11-19. Zakres: projekt i weryfikacja stanu wyjściowego, bez importowania danych.

## 1. Stan wyjściowy i środowisko

**Decision:** Pozostać przy Symfony Console i istniejącym `app:pfs:tournaments:import`; uzupełnić przedział sekwencją pojedynczych wywołań.

**Rationale:** Użytkownik wybrał wariant A podczas clarify. `setup-plan.sh` zwraca logiczną nazwę funkcji `002-fix-pfs-import`, ale rzeczywista gałąź Git jest `main`; nie tworzono gałęzi. Lokalny odczyt bazy potwierdził PFS organization ID 21 i ostatni turniej ID 12136, Kraków 2026-07-18, z pustym `urlid`. Nie zakładać, że numery lokalne będą takie same w innym środowisku.

PHP kontenera: 8.5.8, PHPUnit 13.2.4; Dockerfile wskazuje PHP 8.5, composer deklaruje >=8.3, Symfony 7.2, Doctrine DBAL 3 / ORM 3.3, PostgreSQL 17 i Redis 7. Do walidacji używać kontenera `php`, nie PHP 8.4 hosta.

**Alternatives considered:** Automatyczny importer przedziału odrzucony zgodnie z decyzją użytkownika; nowy framework i aktualizacja zależności nie są potrzebne.

## 2. Źródła, archiwum i parsery

**Decision:** Dodać wspólną usługę archiwum używaną przez import i downloader. Najpierw odnaleźć i zweryfikować lokalny kompletny HTML; pobierać tylko brakujący lub niepełny. Zapis atomowy po walidacji, z metadanymi ID PFS, URL, datą pobrania i SHA-256. Wsteczna obsługa istniejących nazw plików pozostaje.

**Rationale:** `PfsTournamentWebsiteClient` zawsze wykonuje pobranie, a `DownloadPfsTournamentHtmlCommand` ma prywatny algorytm nazw i bezwarunkowy zapis. Archiwum ma kompletne sekcje Strzyżowa i Gdańska, ale od Białegostoku do eliminacji MP sekcje są puste; sam plik nie potwierdza gotowości. Plik Łomianek istnieje, choć wydarzenia nie ma w pobranym kalendarzu 2026; nie wolno włączać go wyłącznie na podstawie nazwy archiwum. Trzeba zweryfikować jego status i zgłosić rozbieżność w raporcie przed uznaniem historii za kompletną.

Pobraną aktualną stronę eliminacji MP przetworzono obecnym parserem, bez zapisu do bazy: 120 zawodników, 120 pozycji klasyfikacji, 891 wpisów rund (łącznie z ewentualnymi pauzami), 639 wierszy rankingu/poczekalni. To potwierdza możliwość odczytu, nie pełną zgodność semantyczną. Istniejąca lokalna zmiana w `PfsTournamentResultsParser.php` należy do użytkownika i musi zostać zachowana. Aktualne identyfikatory sekcji to `p_klasyfikacja`, `p_hh`, `p_rundy`, `p_ranking`; parser obsługuje też starsze warianty.

**Alternatives considered:** Bezwarunkowy re-download powtarza pracę; pełne przepisanie parsera bez wykazanego błędu zwiększa ryzyko. Manifest metadanych obok HTML jest prostszy od osobnej tabeli lub zewnętrznego magazynu.

Źródła: [kalendarz 2026](https://www.pfs.org.pl/kalendarz.php?rok=2026), [eliminacje MP](https://www.pfs.org.pl/turniej.php?id=1399). Weryfikacja kompletności musi porównywać zbiory osób, rund, wyniki lustrzane i podsumowania, nie tylko liczbę dopasowań wyrażeń regularnych.

## 3. Tożsamość, transakcja i ponowienie

**Decision:** Zachować transakcję `TournamentRoundImportService::import()`. Dodać CLI preflight i wynik `unchanged`, identyfikując najpierw po `(organization_id,urlid)`, a dla dawnych wpisów po potwierdzonych metadanych daty/nazwy/miejsca. Weryfikować kompletność względem zwalidowanego źródła; sam rekord turnieju ani niezerowa liczba rankingów nie wystarczają. Konflikt lub częściowy starszy import blokuje automatyczny zapis.

**Rationale:** Obecna transakcja obejmuje osoby, turniej, wyniki, partie, wszystkie cztery rankingi i audyt. Obecny duplicate-check sprawdza jedynie datę/nazwę/miejsce przed transakcją i rzuca konflikt. `urlid` nie ma unikalnego indeksu. Dodać transakcyjną blokadę organizacji i powtórzyć sprawdzenie wewnątrz sekcji krytycznej; wspólny serwis nie może zacząć traktować duplikatu HTTP jako sukcesu.

Resolver indeksuje katalog nazw, uwzględnia kontekst miasta i jawnie potwierdzone aliasy; zachowuje istniejące wyjątki nazw i ukryte profile bez ujawniania wcześniejszych danych. Zmiana miasta sama nie oznacza nowej osoby, podobieństwo nazwiska samo nie uprawnia do scalenia. Potwierdzone mapowania są przechowywane w konfiguracji PFS, walidowane względem organizacji i jednoznacznego profilu. Nierozpoznany uczestnik bez konfliktu może być debiutantem; nieznana osoba występująca wyłącznie w rankingu wymaga wyjaśnienia brakującej historii lub potwierdzenia. Identyczne nierozróżnialne nazwy w źródle są jawnym błędem, nie podstawą zgadywania.

**Alternatives considered:** Fuzzy matching bez potwierdzenia grozi zmianą historii innej osoby. Nowy ekran zarządzania aliasami i automatyczna naprawa wszystkich historycznych duplikatów wykraczają poza zakres. Nie dodajemy migracji unikalności przed audytem danych historycznych.

## 4. Stary ranking i chronologia

**Decision:** Zachować deterministyczne odtworzenie starej metody, rozdzielając odczyt historii, obliczenia domenowe i materializację docelowego zestawienia. Najpierw grupować odczyty historii i nie sortować publicznych wierszy wszystkich pośrednich snapshotów dla pojedynczego importu. Zachować dotychczasowy interfejs i strumieniowanie dla backfillu.

**Rationale:** `OldMethodCurrentRankingServicePostgres` odtwarza od lokalnego ID 9367 także przedturniejowe rankingi i skalpy. Ostatni zapisany ranking n/u nie zawiera dawnych osiągniętych rankingów turniejowych ani statystyk kariery potrzebnych do wygasania historii i powrotów po przerwie. Nie wolno zastąpić rekonstrukcji wyborem ostatnich oficjalnych wyników turniejowych.

Reguły istniejącego kodu wymagające niezależnych przypadków kontrolnych: okno dwóch lat z włączoną dolną granicą; ostatnie całe turnieje do 200 partii, zatrzymanie na pierwszym przekroczeniu bez dobierania starszego mniejszego turnieju; próg 30; ważenie skalpów liczbą gier. Przedturniejowy ranking minimum 100; dla powracającej osoby z >=30 partiami kariery ostatnie całe turnieje do co najmniej 30 partii, także spoza okna; dla krótszej kariery wirtualne brakujące partie z rankingiem 100. Kolejność: dokładny ranking malejąco, liczba partii malejąco, nazwa alfabetycznie. Zachować dotychczasowe zaokrąglenia. Reguły oficjalne pomocniczo potwierdza [opis rankingu PFS](https://www.pfs.org.pl/ranking.php); starą metodę definiuje [dokumentacja projektu](../../documentation/General.md).

Lokalny ID nie jest datą ani ID PFS. Wybór granicy zmiany metody i odcięcia historii trzeba opierać na zweryfikowanym wydarzeniu i kolejności `(dt, dotychczasowy porządek serwisu)` zamiast samym `id <= referenceId`. Najnowszy ranking wybierać po tej samej chronologii. Odtwarzanie obejmuje zależności od punktu zmiany metody, nie tylko dwa lata poprzedzające cel; wcześniej wymagane są poprawny stan początkowy i historia kariery.

Użytkownik rozstrzygnął kolejność równoczesnych turniejów: drugi uwzględnia stary ranking po pierwszym. Dla nowej pary przyjąć udokumentowaną kolejność kalendarza Oświęcim, potem Kunice i utrwalić ją kolejnością pojedynczych importów; zachować istniejący porządek lokalny, jeśli wpisy już istnieją. Oficjalne zestawienia obu stron są identyczne i mają datę 14-09-2026, ale nie oznacza to wspólnego liczenia starego rankingu. Źródła: [Oświęcim](https://www.pfs.org.pl/turniej.php?id=1405), [Kunice](https://www.pfs.org.pl/turniej.php?id=1439).

Ryzyka do pokrycia testami: wykluczenie pauz przez zapytanie o przeciwnika; zliczanie walkowerów; deduplikacja partii faz z powtarzającym się numerem rundy; pusta rekonstrukcja akceptowana w imporcie; dzielenie przez zero przy braku wybranych partii. Zakwalifikowanie partii ustalić z pełnych źródeł i dotychczasowych reguł, a nie przez mechaniczne doliczenie pauzy.

**Alternatives considered:** Trwałe checkpointy rekonstrukcji i nowy magazyn stanu odłożone jako zbędna złożoność. Pełne odtworzenie pozostaje referencją poprawności do porównania z optymalizacją.

## 5. Odświeżanie odczytów

**Decision:** Po zatwierdzeniu importu synchronicznie zmienić dataset version i zweryfikować powodzenie zapisu. Opcjonalne rozgrzewanie uruchamiać po tym kroku, z ustaloną wersją, bez ponownego losowego bumpu. `--no-cache-warmup` pomija wyłącznie rozgrzewanie.

**Rationale:** Dzisiaj launcher uruchamia w tle `app:cache:refresh-after-import --warmup`, więc pominięcie launchera pomija także invalidację. `DatasetVersionService::bumpVersion` nie sprawdza wyniku `save`; trzeba obsłużyć false i wyjątek. Błąd po commit jest osobnym stanem wymagającym naprawy cache, nie rollbackiem turnieju. No-op nie bumpuje wersji i nie uruchamia warmupu.

**Alternatives considered:** Globalne czyszczenie cache i skanowanie kluczy odrzucone przez konstytucję. Sam proces w tle nie stanowi dowodu udostępnienia nowych danych.

## 6. Pomiary i testy

**Decision:** Najpierw testy domenowe i źródłowe, potem transakcja na izolowanym PostgreSQL, następnie odczyty API. Mierzyć osobno źródło, resolver, ranking, zapis i cache: czas, liczbę zapytań oraz peak memory na tej samej kopii danych, z wyłączonym Xdebug.

**Rationale:** Uruchomiono istniejący wąski zestaw: 15 testów, 196 asercji, brak nieudanych asercji; exit 1 z powodu dwóch deprecations `ReflectionMethod::setAccessible()` w teście starego rankingu pod PHP 8.5. Usunąć zbędne wywołania podczas rozszerzania tego testu. Testy serwisu importu mockują transakcję, więc nie dowodzą rzeczywistego rollbacku. Parser testowanej strony przechodzi; nie przypisywać mu hipotetycznych błędów bez fixture.

Cel optymalizacji: 0 pobrań kompletnych stron wyników i 0 mutacji przy no-op; koszt odczytów rekonstrukcji zredukowany z dwóch zapytań na turniej do zapytań porcjowanych, bez zmiany rankingów. Cel czasu: brak pogorszenia mediany trzech ciepłych przebiegów rekonstrukcji na ustalonej kopii danych, przy identycznym wyniku; raportować też czas pierwszego przebiegu. Nie ma obecnie zmierzonego czasu całego uzupełnienia i nie podajemy go jako wyniku.

**Alternatives considered:** Samo porównanie czasu pobierania przez sieć jest niestabilne. Duża liczba testów odtwarzających implementację nie zastąpi niezależnych przypadków rankingowych i źródłowych.
