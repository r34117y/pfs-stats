# Implementation Plan: Naprawa i optymalizacja importu PFS

**Branch**: `main` (Git); `002-fix-pfs-import` (logiczny identyfikator Spec Kit) | **Date**: 2026-11-19 | **Spec**: [spec.md](spec.md)

**Input**: `specs/002-fix-pfs-import/spec.md`, w tym decyzja o pojedynczych wywołaniach i sekwencyjnym starym rankingu dla równoczesnych zawodów.

## Summary

Naprawić i zweryfikować istniejący import pojedynczego turnieju, uzupełnić historię od Krakowa do eliminacji MP i zachować prawidłowe f/w/n/u. Wykorzystać kompletne archiwum, uaktualnić puste pliki, weryfikować spójność całego źródła i tożsamości przed zapisem. Zachować transakcję importu, dodać kontrolowany no-op i synchroniczną invalidację cache. Optymalizować odczyty rekonstrukcji starego rankingu bez zastąpienia jej uproszczoną formułą. Dostarczyć i wykonać runbook kolejnych pojedynczych wywołań, bez nowego importera zakresowego.

## Technical Context

**Language/Version**: PHP 8.5.8 w uruchomionym kontenerze; kod nowy zgodny z nowoczesnym stylem projektu. Composer deklaruje >=8.3, lecz PHPUnit 13.2 wymaga właściwego runtime kontenera.

**Primary Dependencies**: Symfony 7.2 Console/DI, Doctrine DBAL 3 i ORM 3.3, API Platform 4.1 dla istniejących publicznych odczytów, PHPUnit 13.2.4. Bez nowych zależności.

**Storage**: PostgreSQL 17: istniejące turnieje, osoby, wyniki, partie, rankingi i audyt; Redis 7 dla wersjonowanego cache; `tournament_html` i metadane źródeł na dysku.

**Testing**: PHPUnit, CommandTester, pobrane fixtures bez zależności sieciowej; osobny PostgreSQL dla zapisujących testów integracyjnych. Publiczne odczyty i nagłówki cache przez istniejące testy API.

**Target Platform**: Linux, Docker Compose; usługa `php`, katalog aplikacji w kontenerze `/var/www/html`.

**Project Type**: Istniejąca aplikacja web/API z operacyjnym CLI. Główna zmiana w CLI i usługach domenowych, bez nowego GUI lub HTTP endpointu.

**Performance Goals**: 0 ponownych pobrań kompletnych HTML i 0 mutacji danych/no-op. Porcjowane odczyty rekonstrukcji zamiast N+1; identyczne n/u; mediana trzech ciepłych rekonstrukcji na tej samej kopii danych nie gorsza niż baseline, raport czasu zimnego i peak memory. Pomiary wykonać przed i po implementacji, nie zakładać zysku z sieci.

**Constraints**: PFS jako źródło prawdy; kolejność po datach i dotychczasowym porządku serwisu dla tej samej daty. Brak automatycznego fuzzy merge. Atomowy zapis danych turnieju; błąd cache po commit raportowany osobno. Zachować istniejące kontrakty HTTP i lokalne zmiany użytkownika. Produkcyjne wdrożenie wyłącznie przez `bin/deploy-prod.sh`, poza wykonaniem tego etapu planowania.

**Scale/Scope**: 12 pozycji aktualnego kalendarza po Krakowie do ID PFS 1399; zweryfikować gotowość każdej. Rekonstrukcja obejmuje historię od punktu zmiany metody i wcześniejsze dane potrzebne do stanu początkowego/kariery. Nie wykonywać globalnego reimportu.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Zasada | Kontrola przed badaniem | Kontrola po projekcie |
|--------|------------------------|----------------------|
| I. Competition History | Cel dotyczy historii PFS i obu rankingów: PASS | Zachowane struktury, osoby i wyraźna granica przedziału: PASS |
| II. Official Sources | Oficjalny kalendarz/wyniki + archiwum: PASS | Źródła i hashe, weryfikacja kompletności, regresje historycznych formatów i rankingów: PASS |
| III. Cache Correctness | Niezbędna jawna invalidacja: PASS | Synchroniczny dataset bump niezależny od warmupu; bez globalnego skanowania; testy publicznych nagłówków i prywatności bez zmian: PASS |
| IV. Boundaries | CLI deleguje logikę do serwisów: PASS | Istniejące zasoby/providerzy API zachowane; domena i odczyt źródeł w usługach: PASS |
| V. Tested Behavior | Testy parsera, tożsamości i rankingu wymagane: PASS | Regresje źródłowe, transakcja PostgreSQL, kontrakt CLI i cache; zastany błąd deprecations do usunięcia: PASS |
| Operational / Workflow | Brak wdrożenia w planowaniu: PASS | Wskazane źródła, wpływ na API/cache, walidacja, runbook i deploy: PASS |

Nie są wymagane wyjątki od konstytucji. PASS oznacza zgodność projektu; ukończenie implementacji wymaga przejścia zaplanowanych testów.

## Project Structure

### Documentation (this feature)

```text
specs/002-fix-pfs-import/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── cli-import.md
└── checklists/requirements.md
```

`tasks.md` powstanie osobno przez `$speckit-tasks`.

### Source Code (repository root)

```text
src/Command/TournamentImport/
  ImportPfsTournamentCommand.php
  DownloadPfsTournamentHtmlCommand.php
src/Service/
  PfsTournamentWebsiteClient.php
  PfsTournamentCalendarParser.php
  PfsTournamentResultsParser.php
  PfsTournamentPayloadFactory.php
  TournamentRoundImportService.php
  PlayerCatalogService.php
  OldMethodCurrentRanking/
  DatasetVersionService.php
  RefreshCacheAfterImportLauncher.php
  RefreshCacheAfterImportService.php
  PfsTournamentArchive.php                 # nowa współdzielona obsługa plików
  PfsTournamentImportPreflight.php         # tożsamość wydarzenia, kompletność, zależności
  PfsPlayerIdentityResolver.php            # wydzielenie istniejącego rozpoznawania
src/PfsTournamentImport/                   # istniejące DTO + opis źródła/wyniku preflight
config/pfs_player_aliases.yaml             # potwierdzone mapowania; bez sekretów
bin/verify-pfs-import.php                  # odczytowy weryfikator/runbook evidence, nie importer
var/pfs-import/                            # lokalne manifesty historii, wyniki weryfikacji i pomiarów

tournament_html/                          # HTML + metadane archiwum

tests/Command/TournamentImport/
tests/Service/
tests/Integration/                        # nowa rzeczywista transakcja PostgreSQL
tests/Fixtures/pfs_tournament_html/
tests/Api/
documentation/General.md
```

**Structure Decision**: Rozszerzyć obecny układ Symfony; nie tworzyć równoległego systemu importu. Weryfikator jest narzędziem odczytowym: porównuje oficjalne źródła, manifest i dane, nie importuje przedziału. Manifest historii ma ścieżkę konfigurowaną przez DI; nie wymaga nowego trybu komendy.

## Phase 0 — Research complete

Wnioski i alternatywy zapisano w [research.md](research.md). Źródła i parser aktualnej strony zostały sprawdzone, lokalny ostatni turniej potwierdzony odczytem. Rozstrzygnięto kolejność równoczesnych turniejów z użytkownikiem. Nie pozostały decyzje produktowe blokujące projekt.

## Phase 1 — Design

1. **Źródło i walidacja:** współdzielić nazewnictwo archiwum z downloaderem, wiązać po ID i zweryfikowanych metadanych, odczytywać legacy pliki bez zgadywania kolizji. Pobierać do pliku tymczasowego, parsować, sprawdzać spójność sekcji, dopiero potem atomowo zastępować kopię i metadane. Pełny HTML nie oznacza poprawnego odczytu wszystkich wierszy — znaczący nierozpoznany wiersz jest błędem. Walidator otrzymuje surowe sekcje oraz diagnostykę odczytu z numerami wierszy i zakresem źródłowym, obok sparsowanych DTO. Każdy niepusty wiersz klasyfikowany jest jako rozpoznane dane, rozpoznany nagłówek/separator lub nierozpoznany znaczący wiersz. Ostatnia kategoria blokuje walidację; porównanie samych DTO nie wykrywa cicho odrzuconych danych. Oryginalna treść i lokalizacja błędu pozostają dostępne bez utraty przez normalizację. Zachować fallbacki historyczne i wprowadzać poprawki parsera tylko dla udokumentowanych przypadków.
2. **Preflight i historia:** weryfikator odczytowy buduje manifest źródeł zakończonych turniejów wymaganych od punktu zmiany metody do celu, wskazuje oczekiwane rekordy, rozbieżności i potwierdzony porządek tej samej daty. Manifest uwzględnia stan początkowy, liczbę partii i kompletność rankingów. Puste zapowiedzi nie są dowodem nieodbycia zawodów. Operator rozstrzyga usunięte/odwołane zawody na podstawie PFS; manifest przechowuje podstawę wyłączenia. Preflight pojedynczego importu wymaga zweryfikowanego manifestu dla tej procedury i sprawdza obecność/zgodność poprzedników w bazie. Brak poprzednika, bazy sprzed zmiany metody lub wymaganego rankingu blokuje sukces. Manifest opisuje oczekiwane źródła, porządek i zweryfikowany stan bazowy, a nie zmienny kursor wykonania. Poprawne dopisanie przewidzianego turnieju nie unieważnia manifestu i nie wymaga ponownego uruchomienia weryfikatora. Przed każdym importem preflight odczytuje rzeczywisty stan wymaganych poprzedników i ponawia kontrolę pod blokadą organizacji; samo wcześniejsze potwierdzenie w pliku nie zastępuje tej kontroli. Zmiana źródłowego hasha, mapowania tożsamości, porządku albo korekta/usunięcie wcześniej potwierdzonych danych unieważnia odpowiedni fragment historii i jego zależnych następców: wymagany jest ponowny preflight weryfikatora przed kontynuacją. Rollback i no-op nie zmieniają manifestu; po awarii między commit a kolejnym wywołaniem stan postępu wynika z DB.
3. **Tożsamość:** wydzielić indeksowany resolver; respektować jawne aliasy i istniejące profile. Miasto stanowi kontekst rozstrzygnięcia, nie trwały identyfikator. Błąd obejmuje uczestników, ranking główny i poczekalnię, także osoby spoza turnieju. Utworzenie nowej osoby tylko dla rozpoznanego debiutanta; nie tworzyć profilu w ciemno dla nierozpoznanego wiersza rankingowego. Wyjątek potwierdzonego debiutanta z równoczesnego turnieju: jeżeli oficjalne f/w pierwszego wydarzenia zawiera nową osobę uczestniczącą wyłącznie w drugim, pełne zwalidowane wyniki drugiego wydarzenia mogą stanowić dowód jej udziału i tożsamości, powiązany przez ID PFS i hash. Po wykluczeniu kolizji wolno utworzyć profil i powiązanie organizacji w transakcji pierwszego importu oraz zapisać oficjalne f/w. Nie wolno wtedy dopisywać udziału, partii, skalpów ani statystyk kariery drugiego wydarzenia do starego rankingu pierwszego. Drugi import używa tego samego profilu i dopiero wtedy uwzględnia jego rzeczywiste partie. Brak dowodu lub konflikt nadal blokuje import; sam identyczny ranking weekendowy nie dowodzi debiutu.
4. **Idempotencja i zapis:** CLI koordynuje preflight i transakcyjną blokadę organizacji; sprawdzić ponownie tożsamość wydarzenia przed zapisem. Kompletne zgodne dane dają no-op bez audytu/mutacji; rekord częściowy lub konflikt wymaga jawnej korekty. Zachować wspólną transakcję i HTTP conflict dla duplikatu. Nie dodawać unikalnego indeksu na niesprawdzonych danych legacy ani automatycznego overwrite.
5. **Ranking:** poprawić granice historii, odczyty i wybór ostatniego turnieju według chronologii. Zachować odtwarzanie przedturniejowych starych rankingów, skalpów i historii kariery; odczytywać dane porcjami, a w pojedynczym imporcie materializować wyłącznie docelowe publiczne wiersze. Zweryfikować punkt rozdziału metod po metadanych zamiast przenosić lokalne ID 9367 między środowiskami. Sekwencja tej samej daty pozostaje zgodna z ustaleniem, bez wspólnego snapshotu weekendu. Zerowa/pusta rekonstrukcja po punkcie zmiany metody jest jawnym błędem.
6. **Cache i wynik:** po commit wymusić sprawdzony zapis nowej wersji datasetu; dopiero potem opcjonalny warmup z tą wersją i właściwym org. Zachować domyślne zachowanie innych wywołań refresh, dodać możliwość użycia już zmienionej wersji bez ponownego bumpu. Osobno raportować błąd przed commit, commit z nieudaną invalidacją, a także nieuruchomiony warmup. No-op pozostaje bez zapisów; po awarii cache operator wykonuje istniejącą komendę naprawczą.
7. **Dokumentacja i realizacja:** runbook zawiera źródłowe ID, kolejność, log każdego wywołania, oczekiwane weryfikacje i wznowienie od pierwszej nieukończonej pozycji. Importować dopiero po przejściu testów i audytu historii. Wcześniejsze poprawne dane zachować; korygować wyłącznie potwierdzone niezgodności wpływające na cel.

Kontrakty i stany: [cli-import.md](contracts/cli-import.md), [data-model.md](data-model.md). Walidacja i lista turniejów: [quickstart.md](quickstart.md).

Istniejące klucze rankingu: `api.ranking.current.v3.<orgId>` i `api.ranking.old.v3.<orgId>`, z wariantem `.tournament.<localId>`, są opakowane przez `DatasetCache` w `dataset.<version>.<key>`. Wersja jest pod `app.dataset.version`. Projekt zmienia wersję, nie format tych kluczy; testy sprawdzają, że wszystkie zależne odczyty przeszły na nowy namespace i zachowały istniejące nagłówki HTTP.

## Validation and delivery

- Wąskie testy źródeł: legacy i aktualne kompletne HTML, puste sekcje, uszkodzone wiersze, pauzy/remisy, 15 rund, pochodzenie i atomowe pliki. Kalendarium i jego zmiany wymagają rzeczywistego fixture 2026.
- Domenowe testy niezależnych wyliczeń: 29/30 partii, 199/200/201, przekroczenie całym turniejem, dwa lata/leap day, skalpy, powracający gracz i debiut, kolejność ID vs daty, równoczesne zawody, brak historii, pauzy/walkowery/fazy i brak dzielenia przez zero.
- CLI/serwis: no-op, brak mutacji, błędne argumenty, konflikt, nieznana osoba na każdej liście, invalidacja także z `--no-cache-warmup`, błąd cache po commit, blokada równoległych zapisów.
- PostgreSQL: rzeczywisty rollback po błędzie rankingowym obejmuje turniej, nowe profile/powiązania, partie/wyniki, f/w/n/u i audyt; testować w izolowanej bazie, nie w bazie akceptacyjnej.
- API: `/api/tournaments`, `/api/ranking`, `/api/old-rank`, `/api/players` oraz historie zawodników pozostają bez zmian kontraktów; weryfikować nowe dane i nagłówki cache na zimnym i ciepłym odczycie. Nie zmieniać prywatnych odpowiedzi użytkownika.
- Pełne uzupełnienie na kopii danych: porównać każdą pozycję f/w ze źródłem oraz n/u z niezależnym wyliczeniem; raport zawiera wszystkie oczekiwane turnieje i 0 późniejszych. Tryb `verify` dla każdego wydarzenia porównuje także metadane, pełny zestaw uczestników, przypisania do osób, klasyfikację i wszystkie jej wartości, rundy/fazy/stoły, przeciwników, wyniki punktowe oraz pauzy/walkowery z kompletnym źródłem. Porównuje partie logiczne z uwzględnieniem lustrzanego zapisu DB. Każda brakująca, nadmiarowa lub zmieniona pozycja daje różnicę ze wskazaniem pola i exit 1, nawet przy identycznych countach i poprawnych rankingach. Raport ma oddzielne wyniki dla danych turniejowych, f/w oraz n/u.
- Kontrolowane przerwanie i wznowienie: Dowód wznowienia obejmuje dwie izolowane bazy odtworzone z tego samego stanu początkowego i te same źródła/konfigurację. W A wykonać pełną sekwencję bez przerwy; w B zatrzymać proces po zatwierdzeniu Oświęcimia (PFS 1405), przed Kunicami (1439), a następnie wznowić od pierwszej nieukończonej pozycji. Zastosować również kontrolną przerwę po innym pełnym turnieju i test rollbacku. Porównać końcowe dane turniejów, osób/powiązań, partii, wyników, f/w/n/u i semantykę audytu, normalizując wyłącznie techniczne ID pomiędzy bazami, czasy audytu oraz token dataset version. Powiązania i porządek zdarzeń muszą pozostać równe. Oddzielny no-op w tej samej bazie wymaga dokładnej niezmienności także audytu i dataset version. Dowód zapisać z punktami przerwania i wynikiem porównania, zamiast utożsamiać wznowienie z ponownym importem ostatniego turnieju.
- Brak planowanej migracji schematu DB i aktualizacji dependencies. Jeśli implementacja wykaże konieczność zmiany schematu dla zachowania potwierdzonej struktury faz, zaktualizować projekt przed zmianą, dodać migrację i test; nie spłaszczać faz po cichu.
- Zmiany będą wymagały standardowego deployu `bin/deploy-prod.sh`; nie uruchamiać deployu w planowaniu. Archiwum i konfigurację aliasów dostarczyć wraz z kodem, dane importować według runbooka w docelowym środowisku po wdrożeniu. Ta faza kończy się projektem, nie wykonaniem importu.

## Complexity Tracking

Brak naruszeń wymagających wyjątku. Nowe elementy ograniczone do odczytu/archiwum, walidacji i wydzielenia istniejącego dopasowania; bez importera zakresowego i checkpointów rankingu.

## Implementation findings (2026-11-19)

Audyt potwierdził siedem brakujących turniejów rankingowych z marca–maja 2026; obejmuje je istniejący zakres niezbędnych korekt historii (FR-010/FR-014 i T055). Plan źródeł może zostać rozszerzony odczytowym `--include-history-repairs`, bez nowego importera przedziałowego. Oczekiwane historyczne appendy są wyłączone ze stałego zestawu `baselineIds`, natomiast zmiana istniejących danych nadal wymaga ponownego preflightu. Historyczne n/u naprawia wariant istniejącego backfillu `--changed-only`, zachowując równe wiersze.

Eliminacje MP 2023–2025 są już częścią lokalnych wydarzeń 30-rundowych; nie dodawać ich ponownie. Oficjalny kalendarz rozróżnia turnieje rankingowe i pozostałe wydarzenia — ta informacja, nie brak sekcji rankingu, decyduje o wyłączeniu. Istniejące historyczne podziały Pucharu Polski zachowano.

Zaobserwowane źródła wymagają normalizacji przepełnionych kolumn nazw w Wałczu oraz walkoweru 400:0 z `-` zamiast skalpów w Sosnowcu. Starszy HH może jeszcze doliczać walkower do swojego podsumowania; klasyfikacja i oba surowe zestawienia są zachowane, różnice zgłaszane, a udział w rankingu wyznaczany z rzeczywistych kwalifikowanych partii. Nowe niekwalifikowane wpisy mają null `ranko`, bez migracji schematu i bez zmiany reprezentacji istniejących rekordów.

Zweryfikowano odrębną referencję i optymalizację na tej samej niezmienionej historii do Krakowa: identyczny hash wszystkich wierszy, zapytania 253 → 12, mediana około 0,88 → 0,50 s, peak około 50 → 65 MB. Odbiór rzeczywistego uzupełnienia pozostaje osobną fazą, zależną od potwierdzenia tożsamości.
