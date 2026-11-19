# Contract: pojedynczy import PFS

Status: projekt zachowania do wdrożenia. Nie oznacza, że poniższe poprawki już istnieją.

## Existing public command

```text
php bin/console app:pfs:tournaments:import --pfsid=<positive-int> --year=<calendar-year> [--no-cache-warmup]
```

- Jedno wywołanie wskazuje dokładnie jeden turniej. Nie dodajemy `--all`, zakresu ani automatycznej pętli importującej.
- `--pfsid` jest ID URL PFS, nigdy lokalnym kluczem bazy; wymagany ścisły dodatni integer. Odrzucić wartości w rodzaju `1399abc`, ułamki i przepełnienie zamiast rzutować je na poprawne ID.
- `--year` domyślnie bieżący rok zgodnie z obecną komendą; runbook podaje jawnie 2026. Walidować jako poprawny obsługiwany rok.
- `--no-cache-warmup` nie wyłącza invalidacji datasetu; pomija jedynie kosztowne przygotowanie publicznych odczytów.
- Potwierdzony manifest historii i mapowania tożsamości są wstrzykiwaną konfiguracją usług, nie nowym trybem importu przedziału. Brak/nieaktualność wymaganej walidacji historii daje blokadę z instrukcją wykonania weryfikatora. Manifest opisuje oczekiwane źródła, porządek i zweryfikowany stan bazowy, a nie zmienny kursor wykonania. Poprawne dopisanie przewidzianego turnieju nie unieważnia manifestu i nie wymaga ponownego uruchomienia weryfikatora. Przed każdym importem preflight odczytuje rzeczywisty stan wymaganych poprzedników i ponawia kontrolę pod blokadą organizacji; samo wcześniejsze potwierdzenie w pliku nie zastępuje tej kontroli. Zmiana źródłowego hasha, mapowania tożsamości, porządku albo korekta/usunięcie wcześniej potwierdzonych danych unieważnia odpowiedni fragment historii i jego zależnych następców: wymagany jest ponowny preflight weryfikatora przed kontynuacją. Rollback i no-op nie zmieniają manifestu; po awarii między commit a kolejnym wywołaniem stan postępu wynika z DB.

## Source and preflight

1. Odczytać wpis oficjalnego kalendarza roku, odnaleźć jednoznaczne lokalne źródło i zweryfikować tożsamość wydarzenia.
2. Kompletny zwalidowany HTML lokalny nie wymaga pobrania strony wyników; brak lub puste sekcje wymagają pobrania i walidacji świeżej kopii. Błąd sieci nie niszczy poprawnej kopii.
3. Walidator otrzymuje surowe sekcje oraz diagnostykę odczytu z numerami wierszy i zakresem źródłowym, obok sparsowanych DTO. Każdy niepusty wiersz klasyfikowany jest jako rozpoznane dane, rozpoznany nagłówek/separator lub nierozpoznany znaczący wiersz. Ostatnia kategoria blokuje walidację; porównanie samych DTO nie wykrywa cicho odrzuconych danych. Oryginalna treść i lokalizacja błędu pozostają dostępne bez utraty przez normalizację. Sprawdzić zgodność osób, wyników lustrzanych, rund i podsumowań. Wydarzenie z 15 rundami może mieć osoby z mniejszą liczbą partii; nie wymagać prostokątnej macierzy N×15.
4. Zweryfikować osoby we wszystkich listach, potwierdzone aliasy, wymaganych poprzedników, stan rekonstrukcji i jawny porządek tej samej daty. Wyjątek potwierdzonego debiutanta z równoczesnego turnieju: jeżeli oficjalne f/w pierwszego wydarzenia zawiera nową osobę uczestniczącą wyłącznie w drugim, pełne zwalidowane wyniki drugiego wydarzenia mogą stanowić dowód jej udziału i tożsamości, powiązany przez ID PFS i hash. Po wykluczeniu kolizji wolno utworzyć profil i powiązanie organizacji w transakcji pierwszego importu oraz zapisać oficjalne f/w. Nie wolno wtedy dopisywać udziału, partii, skalpów ani statystyk kariery drugiego wydarzenia do starego rankingu pierwszego. Drugi import używa tego samego profilu i dopiero wtedy uwzględnia jego rzeczywiste partie. Brak dowodu lub konflikt nadal blokuje import; sam identyczny ranking weekendowy nie dowodzi debiutu.
5. Porównać istniejący turniej ze źródłem i oczekiwanym stanem czterech rankingów. Zgodny kompletny rekord daje `unchanged`; niezgodny/niepełny daje `blocked`, bez automatycznego overwrite. Rekord legacy z pustym `urlid` może zostać rozpoznany po potwierdzonych metadanych bez dopisywania URL ID przy no-op.

## Concurrency and persistence

- Operacja zapisująca pozyskuje transakcyjną blokadę dla organizacji i sprawdza ponownie duplikat/stan poprzedników. Wszystkie ścieżki zapisujące współdzielonym serwisem respektują blokadę; nie zmienia to odpowiedzi konfliktu istniejącego HTTP importu.
- Turniej, utworzone osoby/powiązania, wyniki, partie, f/w/n/u i audyt są jedną transakcją. Nie utrzymywać blokady podczas pobierania sieciowego.
- Warunek ukończenia rankingu po zmianie zasad oznacza poprawny wynik obu metod; pusty n/u z powodu braków historii nie jest sukcesem.
- Po commit synchroniczna invalidacja i jej potwierdzenie, następnie opcjonalny warmup na już ustalonej wersji. No-op nie zapisuje audytu, nie bumpuje wersji ani nie uruchamia warmupu.

## Outcomes and exit status

| Wynik | Exit | DB | Wymagana informacja |
|-------|------|----|---------------------|
| imported | 0 | Commit | ID bazy/PFS, nazwa, źródło/hash, data/miasto, uczestnicy, partie i wiersze f/w/n/u, stan cache |
| unchanged | 0 | Bez mutacji | Istniejący ID i potwierdzenie kompletności; 0 nowych wierszy; istniejące liczby osobno |
| invalid arguments | 2 | Bez mutacji | Nazwa opcji i oczekiwany format |
| blocked | 1 | Bez zapisu turnieju | ID PFS, źródło, dokładny konflikt osoby/historii/struktury i sposób ponowienia |
| failed before commit | 1 | Brak zmian lub rollback | Etap i przyczyna, brak sukcesu |
| committed-cache-error | 1 | Dane zatwierdzone | Jednoznaczne `committed`, ID turnieju, błąd invalidacji i polecenie naprawcze cache; nie sugerować rollbacku |

Nieuruchomiony lub nieudany opcjonalny warmup przy udanej invalidacji daje ostrzeżenie; dane są widoczne przez zimny odczyt. Raport jest czytelny dla operatora; zachować nazwy metryk `Ranking rows f/w/n/u`, nie wprowadzać wymogu parsowania tabel tekstowych jako stabilnego API. Logi nie zawierają sekretów ani nieujawnionych danych ukrytych profili.

## Read-only verifier (planned supporting interface)

```text
php bin/verify-pfs-import.php --year=2026 --from-pfsid=1419 --through-pfsid=1399 --mode=preflight --output=var/pfs-import
php bin/verify-pfs-import.php --year=2026 --from-pfsid=1419 --through-pfsid=1399 --mode=verify --output=var/pfs-import
```

- `preflight` buduje/aktualizuje dowody źródłowe i manifest zależności; rozpoznaje stan początkowy, braki, konflikty, historyczny split i wcześniejszą historię potrzebną do rekonstrukcji. Może zapisywać HTML i raporty na dysku; nie zapisuje danych aplikacji w DB.
- `verify` porównuje DB ze źródłami oraz n/u z niezależną referencją obliczeń; nie może implementować referencji jako ponownego wywołania testowanego serwisu. Tryb `verify` dla każdego wydarzenia porównuje także metadane, pełny zestaw uczestników, przypisania do osób, klasyfikację i wszystkie jej wartości, rundy/fazy/stoły, przeciwników, wyniki punktowe oraz pauzy/walkowery z kompletnym źródłem. Porównuje partie logiczne z uwzględnieniem lustrzanego zapisu DB. Każda brakująca, nadmiarowa lub zmieniona pozycja daje różnicę ze wskazaniem pola i exit 1, nawet przy identycznych countach i poprawnych rankingach. Raport ma oddzielne wyniki dla danych turniejowych, f/w oraz n/u. Raport obejmuje również błędy zależności.
- Granice `from`/`through` oznaczają wydarzenia i ich daty/porządek, nigdy numeryczny przedział ID.
- Exit 0 dla prawidłowo zweryfikowanego stanu względem trybu: w `preflight` brakujące turnieje docelowe są pracą do wykonania, nie błędem; brakująca wcześniejsza zależność/konflikt jest błędem. W `verify` jakakolwiek nieuzasadniona różnica daje exit 1. Nieprawidłowe opcje dają exit 2.
- Manifest i raport mają wersję formatu, source URL/hash oraz jawne powody wyłączeń. Nie przechowują credentiali ani treści odpowiedzi użytkowników aplikacji.

## Public HTTP compatibility

Bez nowych endpointów i zmian pól odpowiedzi. Publiczne odczyty turniejów/rankingów/profili wykorzystują nowy dataset version. Zachować dotychczasowe autoryzacje i konflikt HTTP przy powtórnym zapisie przez istniejący zasób importowy; CLI `unchanged` nie zmienia tej semantyki. Launcher współdzielony z rollbackiem i innymi importami wymaga regresji swoich obecnych wywołań.

## Potwierdzone naprawy historii wykryte podczas implementacji

Opcjonalne `--include-history-repairs` w odczytowym weryfikatorze włącza do manifestu jawnie znalezione brakujące wcześniejsze wydarzenia rankingowe. Nie uruchamia importów. Manifest zachowuje początkowy zestaw lokalnych ID w `baselineIds`, aby przewidziany historyczny append nie unieważniał potwierdzonego stanu. Ponowne potwierdzenie nadal jest wymagane po korekcie istniejących danych. Plan napraw zapisywany jest w `history-repair-events.json` i pozostaje częścią kolejnego preflightu/verify także po uzupełnieniu tych rekordów.

Starsze eliminacje MP mogą być już częścią 30-rundowego lokalnego turnieju; takie pokrycie jest jawnie raportowane. Nie zastępuje ono brakujących współczesnych wydarzeń. Polecenie `app:ranking:backfill-old-method-snapshots --changed-only` koryguje tylko faktycznie odmienne n/u, z invalidacją datasetu po commit; bez flagi zachowuje dotychczasowy tryb backfillu.

Pauzy i walkowery pozostają wpisami rund, lecz nie zwiększają licznika partii rankingowych. Raport CLI rozdziela `Games` i `Round entries including byes/forfeits`. Rozbieżność zaokrąglonych skalpów HH względem klasyfikacji oraz starsze podsumowania HH uwzględniające walkower są jawnie zgłaszane, a surowe źródła zachowane.
