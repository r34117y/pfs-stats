# Feature Specification: Naprawa i optymalizacja importu turniejów PFS

**Feature Branch**: `main` (bieżąca gałąź; specyfikacja nie tworzy nowej gałęzi)

**Created**: 2026-11-19

**Status**: Draft

**Input**: User description: „Naprawa, aktualizacja i optymalizacja mechanizmu importu turniejów PFS. Uzupełnienie danych od ostatnio zaimportowanego turnieju XXX Otwarte Mistrzostwa Krakowa w Scrabble do XXXIII Mistrzostw Polski — fazy eliminacyjnej, z prawidłowo obliczonym starym rankingiem. Uwzględnienie zmian prezentacji wyników PFS, archiwum tournament_html, brakujących aktualnych wyników oraz zmian nazwiska lub miasta zawodników”.

## Clarifications

### Session 2026-11-19

- Q: Czy uzupełnianie brakujących turniejów ma odbywać się istniejącą komendą uruchamianą kolejno dla każdego turnieju, czy nowym trybem automatycznie importującym cały przedział? → A: A — naprawa istniejącej komendy importującej pojedynczy turniej oraz przygotowanie powtarzalnej procedury uzupełnienia całego przedziału.

- Q: Jak ma działać stary ranking dla równoczesnych turniejów? → A: Kolejno według dotychczasowej kolejności w serwisie: drugi turniej uwzględnia ranking po pierwszym.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Uzupełnienie zakończonych turniejów (Priority: P1)

Jako opiekun serwisu chcę uzupełnić wszystkie brakujące zakończone turnieje PFS po XXX Otwartych Mistrzostwach Krakowa w Scrabble, aż do XXXIII Mistrzostw Polski — fazy eliminacyjnej włącznie, uruchamiając istniejącą komendę osobno dla każdego turnieju według powtarzalnej procedury, aby użytkownicy mieli kompletną historię wyników.

**Why this priority**: Jest to wskazany przez użytkownika końcowy rezultat naprawy; niekompletna historia uniemożliwia wiarygodne statystyki i rankingi.

**Independent Test**: Dla stanu kończącego się turniejem krakowskim wykonać udokumentowaną sekwencję wywołań komendy dla pojedynczych turniejów i porównać listę turniejów, uczestników, klasyfikacje oraz wyniki partii z oficjalnymi wynikami PFS w całym wskazanym przedziale.

**Acceptance Scenarios**:

1. **Given** ostatnim zaimportowanym turniejem są XXX Otwarte Mistrzostwa Krakowa w Scrabble, **When** opiekun uzupełni wskazany przedział, **Then** wszystkie zakończone turnieje rankingowe PFS w tym przedziale są zaimportowane, a najnowszym są XXXIII Mistrzostwa Polski — faza eliminacyjna.
2. **Given** identyfikatory PFS nie odpowiadają kolejności rozegrania zawodów, **When** opiekun wykonuje kolejne wywołania według procedury, **Then** podana w niej kolejność wynika z dat zawodów i zależności rankingowych, a niższe ID turnieju docelowego nie powoduje jego pominięcia.
3. **Given** turniej jest dopiero zaplanowany albo publikuje niepełne wyniki, **When** oceniana jest jego gotowość do importu, **Then** nie jest oznaczany jako kompletnie zaimportowany, a opiekun otrzymuje przyczynę.

---

### User Story 2 - Wiarygodne rankingi po każdym turnieju (Priority: P1)

Jako użytkownik serwisu chcę widzieć oficjalny ranking oraz poprawnie odtworzony stary ranking po każdym uzupełnionym turnieju, aby porównywać wyniki zawodników według obu metod.

**Why this priority**: Poprawny stary ranking jest jawnym warunkiem odbioru, a jego wyliczenie wymaga kompletnej wcześniejszej historii.

**Independent Test**: Dla ustalonej historii przygotować niezależne wyliczenie starego rankingu i porównać wszystkie pozycje, wartości i przynależność do poczekalni po każdym uzupełnionym turnieju, w tym docelowym.

**Acceptance Scenarios**:

1. **Given** PFS publikuje ranking i poczekalnię po turnieju, **When** import zakończy się powodzeniem, **Then** oficjalny ranking i poczekalnia odpowiadają opublikowanemu zestawieniu.
2. **Given** kompletna historia zawodnika w wymaganym oknie, **When** wyznaczany jest stary ranking, **Then** wybierane są ostatnie turnieje z dwóch lat, zamiast najlepszych, z limitem 200 partii i pozostałymi obowiązującymi regułami opisanymi w dokumentacji projektu.
3. **Given** zawodnicy na granicach okna dwóch lat, limitu 200 partii i progu 30 partii, **When** wyznaczany jest ranking, **Then** wynik, kolejność i podział na ranking główny oraz poczekalnię są zgodne z niezależnym wyliczeniem reguł domenowych.
4. **Given** brak wcześniejszego turnieju potrzebnego do wyliczenia, **When** opiekun próbuje zakończyć import zależnego turnieju, **Then** system wskazuje brakującą historię i nie przedstawia zależnego starego rankingu jako poprawnie ukończonego.

---

### User Story 3 - Obsługa aktualnych i historycznych źródeł (Priority: P2)

Jako opiekun serwisu chcę korzystać z archiwalnych wyników oraz pobierać brakujące lub niepełne wyniki PFS, aby zmiany wyglądu strony nie blokowały importu ani nie psuły danych historycznych.

**Why this priority**: Archiwum pozwala zweryfikować zgodność danych i ograniczyć zależność od dostępności strony PFS.

**Independent Test**: Przetworzyć zestaw kompletnych stron historycznych, aktualnych wyników i pustej strony zapowiedzi; porównać odczytane wyniki ze źródłami i sprawdzić konieczność pobrania aktualizacji.

**Acceptance Scenarios**:

1. **Given** kompletne archiwalne wyniki w obsługiwanym formacie, **When** wykonywany jest import z archiwum, **Then** wyniki są zgodne ze źródłem bez ponownego pobrania tej strony.
2. **Given** brak pliku albo plik zawierający wyłącznie zapowiedź i puste sekcje wyników, **When** wyniki są już opublikowane na PFS, **Then** aktualne wyniki zostają pobrane, zachowane w archiwum i wykorzystane do importu.
3. **Given** zmieniony układ strony PFS lub odmienna liczba rund, **When** przetwarzane są kompletne wyniki, **Then** uczestnicy, partie, klasyfikacja i rankingi zachowują znaczenie oraz wartości podane w źródle.
4. **Given** niedostępna strona albo niespójne wyniki, **When** odczyt nie może zakończyć się poprawnie, **Then** opiekun otrzymuje identyfikację źródła i opis problemu, bez zastąpienia poprawnych danych niepełnym wynikiem.

---

### User Story 4 - Ciągłość tożsamości zawodnika (Priority: P2)

Jako użytkownik chcę, aby wyniki tej samej osoby pozostawały na jednym profilu mimo zmiany nazwiska lub miasta, a osoby o podobnych danych nie były mylone.

**Why this priority**: Błędne przypisanie wyników zniekształca rankingi i historię zawodników.

**Independent Test**: Sprawdzić potwierdzoną zmianę miasta, potwierdzoną zmianę nazwiska, debiutanta oraz dwie różne osoby o takich samych lub podobnych danych.

**Acceptance Scenarios**:

1. **Given** potwierdzone powiązanie wcześniejszych i aktualnych danych tej samej osoby, **When** import napotyka zmienione nazwisko lub miasto, **Then** wyniki trafiają do istniejącego profilu, z zachowaniem wcześniejszej historii.
2. **Given** rzeczywisty debiutant bez konfliktu z istniejącymi osobami, **When** jest importowany, **Then** powstaje nowy profil z prawidłowo przypisanymi wynikami. Jeśli jego debiut potwierdzają kompletne wyniki drugiego równoczesnego turnieju, a występuje już w oficjalnym rankingu pierwszego, można utworzyć ten profil przy pierwszym imporcie, bez przypisania mu wtedy udziału lub partii drugiego wydarzenia i bez wpływu tych partii na wcześniejszy stary ranking.
3. **Given** kilka możliwych dopasowań albo podejrzenie zmiany nazwiska bez potwierdzenia, **When** przypisanie jest niejednoznaczne, **Then** system zgłasza przypadek do rozstrzygnięcia i nie zatwierdza zależnych danych na podstawie zgadywania; po potwierdzeniu powiązania opiekun może ponowić import.

---

### User Story 5 - Sprawne ponowienie i kontrola importu (Priority: P2)

Jako opiekun serwisu chcę ponawiać import po przerwaniu oraz widzieć jego wynik, aby uzupełnienie danych nie wymagało powtarzania ukończonej pracy.

**Why this priority**: Wieloturniejowe uzupełnienie musi być powtarzalne, weryfikowalne i oszczędnie korzystać ze źródeł.

**Independent Test**: Przerwać procedurę między wywołaniami komendy dla kolejnych turniejów, wznowić od pierwszego nieukończonego turnieju, a następnie ponowić wywołania dla niezmienionych źródeł i porównać dane oraz raporty.

**Acceptance Scenarios**:

1. **Given** część turniejów została poprawnie zaimportowana, **When** opiekun wznawia procedurę od pierwszego nieukończonego turnieju, **Then** ukończone dane pozostają stabilne, a brakujące są uzupełniane bez duplikatów; ponowne wywołanie dla ukończonego turnieju również nie zmienia poprawnych danych.
2. **Given** błąd podczas zapisu turnieju, **When** import kończy się niepowodzeniem, **Then** nie pozostawia turnieju częściowo zatwierdzonego jako ukończony i pozwala na poprawne ponowienie.
3. **Given** zakończona próba importu, **When** opiekun przegląda wyniki kolejnych wywołań i zestawienie wykonania procedury, **Then** widzi turnieje ukończone, pominięte i zablokowane, liczby uczestników, partii i wpisów rankingowych oraz przyczyny problemów; nie jest wymagany osobny zbiorczy raport generowany przez nowy tryb importu.
4. **Given** pomyślnie uzupełnione dane, **When** użytkownik przegląda wyniki i rankingi w serwisie po zakończeniu operacji, **Then** widzi aktualne dane zamiast wcześniejszego, niepełnego stanu.

### Edge Cases

- Dwa turnieje mają tę samą datę: stary ranking jest liczony kolejno według dotychczasowej kolejności w serwisie; drugi turniej uwzględnia ranking po pierwszym. Identyczne oficjalne zestawienia PFS pozostają importowane bez zmian.
- Turniej ma 7, 13 lub 15 rund, grupy, fazę pucharową, pauzy, remisy, walkowery albo nie wszyscy zawodnicy rozegrali komplet partii.
- Istnieje plik z nazwą docelowego turnieju, lecz jego sekcje wyników są puste.
- Zmieniają się nazwy sekcji, odstępy, znaki diakrytyczne lub sposób prezentacji nazwisk i miast.
- Ranking zawiera osoby nieuczestniczące w danym turnieju oraz odrębną poczekalnię.
- Brakuje części wymaganej historii rankingowej sprzed turnieju krakowskiego.
- Zmiana nazwiska i miasta występuje jednocześnie; dwie różne osoby mają identyczne nazwisko i imię.
- Ponowienie następuje po błędzie odczytu, błędzie zapisu albo ręcznym rozstrzygnięciu tożsamości.
- Źródło nie zawiera rankingu albo przedstawia dane sprzeczne pomiędzy zestawieniami zawodników i rund; brak nie może zostać potraktowany jak poprawny pusty ranking.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Istniejąca komenda MUST importować pojedynczy turniej. Zadanie MUST dostarczyć powtarzalną procedurę kolejnych wywołań tej komendy, umożliwiającą uzupełnienie wszystkich brakujących zakończonych turniejów rankingowych PFS po turnieju krakowskim do wskazanych eliminacji MP włącznie, bez ręcznej edycji wyników każdej partii. Nowy tryb automatycznego importu całego przedziału nie jest wymagany.
- **FR-002**: Procedura uzupełnienia MUST zawierać listę turniejów i ich identyfikatorów ustaloną na podstawie oficjalnego kalendarza i stron wyników PFS, kolejność wywołań zgodną z chronologią potrzebną rankingom, niezależną od kolejności ID, oraz sposób wznowienia od pierwszego nieukończonego turnieju. Dla zdarzeń równoczesnych stary ranking MUST zachowywać dotychczasową kolejność w serwisie: drugi turniej korzysta z rankingu po pierwszym; procedura MUST jawnie zapisać tę kolejność. Oficjalne zestawienia PFS pozostają zgodne ze źródłem. Komenda nie musi automatycznie wykrywać całego przedziału.
- **FR-003**: System MUST odróżniać kompletne wyniki od zapowiedzi, pustych sekcji oraz wyników niepełnych i nie uznawać tych ostatnich za zakończony import.
- **FR-004**: System MUST odczytywać reprezentatywne historyczne i aktualne formaty wyników, zachowując uczestników, daty, miejsce, końcową klasyfikację, rundy, przeciwników, wyniki punktowe i opublikowane wartości rankingowe. Znaczący nierozpoznany wiersz MUST powodować zgłoszenie błędu z lokalizacją w źródle, zamiast cichego pominięcia.
- **FR-005**: System MUST zachowywać rzeczywistą strukturę turnieju, w tym odmienne liczby rund, fazy rozgrywek i szczególne rozstrzygnięcia partii, bez dopisywania nieodbytych partii.
- **FR-006**: System MUST wykorzystywać kompletne archiwalne wyniki jako materiał weryfikacyjny i źródło importu oraz pobierać z PFS i archiwizować wyniki brakujące lub niepełne. Istniejące pliki nie mogą zostać uznane za kompletne jedynie na podstawie nazwy.
- **FR-007**: System MUST zachowywać powiązanie importowanych danych z turniejem PFS i użytym źródłem; oficjalne dane PFS mają pierwszeństwo przed pomocniczymi serwisami statystycznymi, a wykryte sprzeczności wymagają zgłoszenia.
- **FR-008**: System MUST importować oficjalny ranking główny i poczekalnię po każdym uzupełnianym turnieju, zgodnie ze źródłem, również dla osób niebędących uczestnikami tego turnieju.
- **FR-009**: System MUST wyliczać stary ranking główny i jego poczekalnię po każdym uzupełnianym turnieju, zgodnie z dokumentacją projektu: ostatnie turnieje z dwuletniego okna, limit 200 partii oraz próg 30 partii dla rankingu głównego. Pozostałe reguły kwalifikacji, punktacji, remisów i zaokrągleń MUST zachować dotychczasowe znaczenie domenowe.
- **FR-010**: System MUST wykrywać brak historii wymaganej do obliczeń i wskazywać zależne turnieje, których rankingu nie można jeszcze uznać za ukończony; późniejsze sukcesy nie mogą maskować wcześniejszej luki. Poprawny import kolejnego przewidzianego turnieju MUST pozwalać kontynuować procedurę bez ponownego zatwierdzania niezmienionych źródeł. Korekta lub usunięcie potwierdzonej historii, zmiana źródła, powiązań osób albo porządku MUST wymagać ponownej kontroli zależnych danych przed kontynuacją.
- **FR-011**: System MUST przypisywać wyniki i rankingi do jednej osoby mimo potwierdzonych zmian nazwiska lub miasta, zachowując historię, i tworzyć nowe profile dla rozpoznanych debiutantów.
- **FR-012**: System MUST zgłaszać niejednoznaczne dopasowania osób i umożliwiać ponowienie po potwierdzeniu tożsamości przez opiekuna, bez automatycznego łączenia różnych osób lub tworzenia podejrzanego duplikatu.
- **FR-013**: System MUST umożliwiać ponowienie po błędzie bez duplikatów turniejów, osób, partii i rankingów oraz bez pozostawiania częściowego turnieju jako ukończonego.
- **FR-014**: System MUST zachować wcześniej poprawnie zaimportowane dane; korekta historyczna jest wymagana wyłącznie w zakresie potwierdzonej niezgodności wpływającej na import lub ranking docelowy.
- **FR-015**: Każde wywołanie komendy MUST raportować wynik wskazanego turnieju, liczby uczestników, partii i wpisów obu rankingów oraz konkretne przyczyny pominięcia lub błędu. Procedura MUST określać sposób zestawienia tych wyników dla całego przedziału; nie jest wymagany nowy mechanizm zbiorczego raportowania.
- **FR-016**: System MUST unikać ponownego pobierania kompletnych, niezmienionych wyników dostępnych w archiwum podczas wznowienia i pomijać ponowny zapis już poprawnie ukończonych danych.
- **FR-017**: Po pomyślnym imporcie system MUST udostępnić zaktualizowane wyniki i rankingi użytkownikom serwisu, uwzględniając odświeżenie wcześniej prezentowanych danych.

### Key Entities *(include if feature involves data)*

- **Turniej PFS**: Wydarzenie o identyfikatorze źródłowym, nazwie, dacie, miejscu i strukturze rozgrywek; identyfikator nie określa chronologii zawodów.
- **Źródło wyników**: Oficjalna strona PFS albo jej archiwalna kopia, o określonej kompletności i powiązaniu z wydarzeniem.
- **Zawodnik i potwierdzone warianty danych**: Jedna osoba z historią wyników oraz wcześniejszymi lub aktualnymi nazwiskami i miastami; warianty nie oznaczają odrębnych osób.
- **Partia i udział w turnieju**: Przeciwnicy, runda, punkty i rozstrzygnięcie oraz zbiorczy wynik zawodnika w turnieju.
- **Ranking po turnieju**: Zestawienie osób, pozycji i wartości według oficjalnej lub starej metody, z osobną poczekalnią i okresem uwzględnianej historii.
- **Wynik importu**: Status turnieju, liczby zaimportowanych danych, źródło, problemy i zależności wymagające rozstrzygnięcia.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Po zakończeniu uzupełnienia obecne jest 100% zakończonych turniejów rankingowych PFS ze wskazanego przedziału, a najnowszym zaimportowanym turniejem są XXXIII Mistrzostwa Polski — faza eliminacyjna; liczba zaimportowanych późniejszych wydarzeń wynosi 0.
- **SC-002**: Dla każdego uzupełnionego turnieju pełne zestawy uczestników, przypisania osób, metadane, partie z rundami/fazami, przeciwnikami, punktami i szczególnymi rozstrzygnięciami, wszystkie wartości końcowej klasyfikacji oraz oficjalny ranking i poczekalnia są w 100% zgodne z kompletnymi źródłami PFS. Kontrola wykrywa każdą zmianę wartości, brak i nadmiar pozycji, także przy niezmienionych liczbach rekordów.
- **SC-003**: Po każdym uzupełnionym turnieju 100% pozycji i wartości starego rankingu oraz jego poczekalni odpowiada niezależnemu wyliczeniu na tej samej historii, z dokładnością do obowiązującego zaokrąglenia; dotyczy to także eliminacji MP.
- **SC-004**: Ponowny import niezmienionych źródeł tworzy 0 duplikatów i powoduje 0 zmian poprawnych wyników lub rankingów. Dwa przebiegi z tego samego stanu początkowego — pełny oraz kontrolowanie przerwany między turniejami i wznowiony — MUST dać identyczne dane domenowe i semantykę audytu, także po przerwaniu między Oświęcimiem a Kunicami. Dopuszczalne różnice pomiędzy izolowanymi bazami ograniczają się do technicznych identyfikatorów przy zachowaniu powiązań, czasów audytu i tokenu wersji danych; no-op w tej samej bazie nie zmienia także audytu ani wersji danych.
- **SC-005**: Dla zestawu kontrolnego obejmującego zmianę nazwiska, zmianę miasta, debiutanta i konflikt danych występuje 0 błędnych połączeń osób, 0 nieuzasadnionych duplikatów oraz 100% jawnie zgłoszonych nierozstrzygniętych przypadków.
- **SC-006**: Każdy rozpatrzony turniej ma jeden jawny status końcowy, a 100% błędów źródła, braków historii i konfliktów tożsamości jest wskazane w raporcie zamiast ukryte pod komunikatem sukcesu.
- **SC-007**: Wznowienie z kompletnym, niezmienionym archiwum wymaga 0 ponownych pobrań stron wyników ukończonych turniejów i 0 ponownych zapisów poprawnie ukończonych turniejów.
- **SC-008**: Po zakończeniu importu użytkownik widzi wyniki i oba rankingi turnieju docelowego bez ręcznego odświeżania danych przez administratora.

## Assumptions

- Stan początkowy wskazany przez użytkownika kończy się turniejem krakowskim; zostanie zweryfikowany przed wykonaniem uzupełnienia. Granica odbioru jest stała i nie rozszerza się automatycznie o późniejsze zawody.
- „Stary ranking” oznacza metodę opisaną w dokumentacji projektu, a nie nowy system punktacji. Zakres obejmuje zarówno ranking główny, jak i jego poczekalnię; nie obejmuje zmiany reguł rankingowych.
- Historia poprzedzająca import jest podstawą obliczeń. Nie przewiduje się bezwarunkowego ponownego importu całego archiwum; wykryte braki lub błędy konieczne dla poprawnego wyniku docelowego trzeba uzupełnić lub skorygować.
- PFS jest źródłem prawdy; archiwalne kopie kompletnych wyników służą do odtwarzania i weryfikacji. Dostęp do PFS jest wymagany dla brakujących lub niekompletnych źródeł.
- Obsługa konfliktów tożsamości może wymagać rzadkiego potwierdzenia przez opiekuna; nie zakłada się możliwości automatycznego rozstrzygnięcia każdej zmiany nazwiska i miasta.
- Optymalizacja oznacza mierzalne ograniczenie zbędnego pobierania i powtarzania ukończonej pracy bez zmiany wyników. Cel czasowy zostanie ustalony w planowaniu po pomiarze stanu wyjściowego.
- Zakres obejmuje naprawę istniejącej komendy importującej pojedynczy turniej, przygotowanie i wykonanie powtarzalnej procedury uzupełnienia danych oraz niezbędną weryfikację zgodności historycznych formatów. Nowy tryb automatycznie wykrywający i importujący cały przedział, nowy interfejs graficzny, import rozgrywek klubowych i automatyczne wdrożenie produkcyjne pozostają poza zakresem.
- Dokumentacja domenowa: [General.md](../../documentation/General.md). Źródła oficjalne: [strona PFS](https://www.pfs.org.pl/index.php), [kalendarz PFS 2026](https://www.pfs.org.pl/kalendarz.php?rok=2026), [turniej początkowy — ID 1419](https://www.pfs.org.pl/turniej.php?id=1419), [turniej docelowy — ID 1399](https://www.pfs.org.pl/turniej.php?id=1399).

### Potwierdzone tożsamości — 2026-11-19

Użytkownik potwierdził Marię Magdalenę Kupczyńską jako Magdalenę Kupczyńską, scalenie profili Michała Waszkiewicza oraz zmianę nazwiska Zuzanny Adamczewskiej na Stasiak. Oba profile każdej potwierdzonej osoby zachowują wspólną historię po scaleniu. Dla Semenov zezwolił na utożsamienie wyłącznie przy zgodności rankingów. Źródło zawiera obie zawodniczki jednocześnie: Zuzannę Semenov (118,14, 827 skalpów / 7 partii) i Dorotę Ninę Semenov (107,29, 751 / 7). Drugi zestaw odpowiada istniejącemu profilowi Dorota Nina; osoby pozostają oddzielne. Dodatkowy historyczny skrót Grażyna Maria odpowiada pełnej nazwie Grażyna Maria Nowak na podstawie tego samego występu Wrocław 2024-07-28 i rankingu 97,43 (682 / 7).
