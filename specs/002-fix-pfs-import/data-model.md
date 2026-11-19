# Data model: import PFS

## Existing persisted entities

| Encja | Klucz i pola istotne dla importu | Powiązania / reguły |
|-------|----------------------------------|--------------------|
| Tournament | lokalne `id`, `organization_id`, `urlid`, `dt`, `name`, `fullname`, `place`, `rounds`, `players_count` | `urlid` jest ID PFS, nie lokalnym ID; dopasowanie legacy dopuszcza null urlid. `dt` przechowuje obecną datę startową; nie zmieniać jej na koniec imprezy bez migracji znaczenia. |
| Player | `id`, nazwy wyświetlane/sortujące, imię/nazwisko, istniejące miasto | Osoba nie jest identyfikowana przez samo miasto. Nie nadpisywać ukrytego profilu historycznym nazwiskiem. |
| Player organization | `player_id`, `organization_id` | Profil docelowy i alias muszą należeć do tej organizacji. |
| Tournament result | turniej, osoba, pozycja startowa/końcowa, ranking turniejowy, punkty/składniki wyniku | Dokładnie jeden udział danej osoby w wydarzeniu; zgodność z klasyfikacją i HH. |
| Tournament game | turniej, runda/stół, osoba/przeciwnik, punkty, wynik | Uwzględnić istniejący zapis lustrzany; null przeciwnika może oznaczać pauzę, nie uszkodzony rekord. Liczba fizycznych wierszy nie jest liczbą partii. |
| Ranking | turniej, osoba, typ f/w/n/u, pozycja, wartość, skalpy/liczba gier według istniejącego modelu | f/w z PFS; n/u z rekonstrukcji starej metody; unikalność logiczna osoba/turniej/typ. Nie mylić precyzji składowanych i wyświetlanych wartości. |
| Import audit | istniejący text resource typu 9001 | Audyt razem z danymi w transakcji; no-op nie tworzy nowego audytu. |

Plan nie zakłada zmiany tabel. PFS URL ID nie jest obecnie unikalny w DB; transakcyjna blokada organizacji i walidacja zapobiegają nowym kolizjom, nie naprawiają automatycznie dawnych.

## Source artifact (filesystem)

- `pfsId`, `sourceUrl`, `calendarYear`, daty start/koniec, nazwa i miejsce z oficjalnego kalendarza.
- `htmlPath`, `sha256`, czas pobrania w UTC, `formatVersion` metadanych, wynik pełnej walidacji i liczby odczytanych elementów.
- Diagnostyka parsowania jako towarzyszący DTO wynik odczytu: surowe sekcje, identyfikator sekcji, numer/zakres wiersza, oryginalny tekst i klasyfikacja wiersza. Walidator otrzymuje surowe sekcje oraz diagnostykę odczytu z numerami wierszy i zakresem źródłowym, obok sparsowanych DTO. Każdy niepusty wiersz klasyfikowany jest jako rozpoznane dane, rozpoznany nagłówek/separator lub nierozpoznany znaczący wiersz. Ostatnia kategoria blokuje walidację; porównanie samych DTO nie wykrywa cicho odrzuconych danych. Oryginalna treść i lokalizacja błędu pozostają dostępne bez utraty przez normalizację.
- HTML pozostaje w `tournament_html`; metadane obok pliku, np. `<filename>.meta.json`.
- Stare pliki bez metadanych można użyć po jednoznacznym dopasowaniu i pełnej walidacji. Nie trzeba modyfikować ich przy każdym no-op. Niejednoznaczność zgłaszać.
- Hash wykrywa zmianę lokalnej kopii; nie dowodzi, że PFS nigdy nie opublikował korekty. Ponowne pobranie po udokumentowanej korekcie jest operacją świadomą.
- Zapis plików ma staging i atomic rename. Niespójna para HTML/metadane po przerwaniu jest wykrywana przez hash i ponownie walidowana; nie może dać fałszywego sukcesu.

## Confirmed player mapping (configuration)

`config/pfs_player_aliases.yaml`: organizacja, nazwa źródłowa i opcjonalny kontekst miasta, jednoznaczny istniejący profil docelowy, podstawa potwierdzenia. Wybór klucza docelowego musi zostać zweryfikowany w danym środowisku; nie kopiować niezweryfikowanych numerycznych ID z innej bazy.

- Najpierw jawne potwierdzone mapowanie, potem dokładna/znormalizowana nazwa z wykryciem kolizji.
- Alias nie tworzy ani nie scala profili; kieruje nowe dane do ustalonej osoby.
- Miasto może rozstrzygnąć konflikt tylko przy wystarczającym potwierdzeniu, nie stanowi dowodu zmiany nazwiska.
- Zapis domyślnie nie zmienia historycznej nazwy osoby; normalizacja whitespace/diakrytyki do wyszukiwania nie zmienia surowego źródła.

Wyjątek potwierdzonego debiutanta z równoczesnego turnieju: jeżeli oficjalne f/w pierwszego wydarzenia zawiera nową osobę uczestniczącą wyłącznie w drugim, pełne zwalidowane wyniki drugiego wydarzenia mogą stanowić dowód jej udziału i tożsamości, powiązany przez ID PFS i hash. Po wykluczeniu kolizji wolno utworzyć profil i powiązanie organizacji w transakcji pierwszego importu oraz zapisać oficjalne f/w. Nie wolno wtedy dopisywać udziału, partii, skalpów ani statystyk kariery drugiego wydarzenia do starego rankingu pierwszego. Drugi import używa tego samego profilu i dopiero wtedy uwzględnia jego rzeczywiste partie. Brak dowodu lub konflikt nadal blokuje import; sam identyczny ranking weekendowy nie dowodzi debiutu. Nowo utworzony profil jest objęty audytem i rollbackiem pierwszego importu; późniejsze referencje chronią go przed usunięciem przy cofnięciu.

## Verified dependency manifest (local validation artifact)

Plik w `var/pfs-import` zawiera wersję formatu, organizację, wydarzenie rozdziału metod, źródłowy stan początkowy, docelowy PFS ID, listę wymaganych wydarzeń i ich źródeł/hashów, daty i jawny porządek dla tej samej daty, mapowanie na lokalne ID oraz potwierdzone wyłączenia wydarzeń odwołanych/nierankingowych. Powstaje przez odczytowy weryfikator z kalendarzy i wyników PFS oraz stanu lokalnego, nie przez zgadywanie ciągłości ID.

Preflight sprawdza źródła i rzeczywisty stan zależności: brak wymaganej pozycji, uszkodzony/niezgodny wynik albo brak rankingów potrzebnych rekonstrukcji blokuje zatwierdzenie zależnego importu. Manifest opisuje oczekiwane źródła, porządek i zweryfikowany stan bazowy, a nie zmienny kursor wykonania. Poprawne dopisanie przewidzianego turnieju nie unieważnia manifestu i nie wymaga ponownego uruchomienia weryfikatora. Przed każdym importem preflight odczytuje rzeczywisty stan wymaganych poprzedników i ponawia kontrolę pod blokadą organizacji; samo wcześniejsze potwierdzenie w pliku nie zastępuje tej kontroli. Zmiana źródłowego hasha, mapowania tożsamości, porządku albo korekta/usunięcie wcześniej potwierdzonych danych unieważnia odpowiedni fragment historii i jego zależnych następców: wymagany jest ponowny preflight weryfikatora przed kontynuacją. Rollback i no-op nie zmieniają manifestu; po awarii między commit a kolejnym wywołaniem stan postępu wynika z DB. Brak wpisu poczekalni nie oznacza sam w sobie błędu, jeśli poprawny wyliczony zestaw jest pusty; weryfikować treść i reguły, nie arbitralną niezerowość tabel.

## Old-method replay state (in memory)

- Historia osoby: chronologiczne wyniki całych turniejów, daty, liczba kwalifikowanych partii i ranking osiągnięty starą metodą.
- Statystyki kariery: liczba partii i skalpów potrzebna dla rankingu przedturniejowego.
- Poprzedni stary ranking główny oraz stan początkowy sprzed zmiany zasad.
- Porządek wydarzenia: data i jawna dotychczasowa kolejność w serwisie; URL ID ani kolejność rejestracji nie wyznaczają daty.
- Snapshot: ranking główny od 30 partii i poczekalnia poniżej 30; maksymalnie 200 partii całymi najnowszymi turniejami; dane nie mogą zawierać wydarzeń późniejszych od referencyjnego w tej chronologii.

Nie utrwalać nowego checkpointu. Snapshot n/u sam nie wystarcza do wznowienia rekonstrukcji.

## State transitions

```text
source missing/incomplete -> fetch -> validate -> archive ready
source complete ----------> validate ----------> archive ready
archive ready -> identities/dependencies validated -> import lock -> recheck
recheck -> existing complete and equal -> unchanged (no DB writes)
recheck -> absent -> transaction -> commit -> dataset bump -> imported
transaction failure -> rollback -> failed
commit -> dataset bump failure -> committed-cache-error -> explicit cache repair
validation/identity/history conflict -> blocked (no tournament writes)
```

Warmup jest opcjonalnym krokiem po skutecznej invalidacji. Nie jest warunkiem zachowania danych w DB. Częściowy zastany import wymaga świadomej korekty, nie automatycznego nadpisania. Statusy są wynikiem operacji/raportu, nie nową tabelą workflow.

## Ustalenia implementacyjne

- Manifest przechowuje jawne `baselineIds`; odciski dotyczą tego stałego zbioru, więc dopisanie uzgodnionych wcześniejszych turniejów nie unieważnia manifestu. Korekta istniejących rankingów bazowych wymaga ponownej weryfikacji.
- `--include-history-repairs` obejmuje siedem potwierdzonych braków z marca–maja 2026. Eliminacje MP 2023–2025 są już częścią historycznych turniejów 30-rundowych i nie podlegają ponownemu importowi.
- Partie z pauzą oraz walkowery 400:0/0:400 zachowują źródłowe wyniki; `ranko = NULL` oznacza wyłączenie nowego wpisu z obliczeń rankingowych. Historyczny zapis jest nadal obsługiwany przez rekonstrukcję.
- Alias bez miasta znajduje się w `aliases`, a potwierdzony alias zależny od miasta w `context_aliases`. Miasto samo nie wystarcza do połączenia profili.
- Ostrzeżenia zachowują rozpoznane rozbieżności źródłowe: zaokrąglenia skalpów i historyczne podsumowanie walkoweru. Nieznany istotny wiersz lub różnica partii blokuje walidację.
