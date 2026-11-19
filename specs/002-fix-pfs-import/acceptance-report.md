# Raport odbioru importu PFS — 2026-11-19

Cel osiągnięty w lokalnej bazie aplikacji `app` (PostgreSQL Compose, organizacja PFS 21). Najnowszym turniejem jest **XXXIII Mistrzostwa Polski — faza eliminacyjna**, PFS **1399**, lokalne ID **12160**, data początku 2026-11-14. Nie zaimportowano późniejszych wydarzeń.

Przed zmianami zachowano odtwarzalny dump `var/pfs-import/target-before-import.dump`; SHA-256 jest w `var/pfs-import/target-results/backup.sha256`. Próby wykonano na osobno odtworzonych bazach `pfs_acceptance` i `pfs_resume`, z Redis 13 i 12. Baza aplikacji używa Redis 0; zapisujące testy używały `pfs_import_test` oraz Redis 14. Runtime: PHP 8.5.8, PHPUnit 13.2.4.

## Wynik odbioru

- 19 pojedynczych importów: siedem koniecznych uzupełnień wcześniejszej historii i dwanaście turniejów po Krakowie.
- Pełne porównanie metadanych, uczestników, klasyfikacji i wszystkich partii z archiwum: zero różnic dla każdego turnieju.
- Oficjalne f/w porównane ze źródłem PFS; n/u porównane z niezależnym kalkulatorem: zero różnic dla każdego turnieju. Raport zachowuje wszystkie źródłowe, referencyjne i zapisane pozycje.
- Docelowy turniej: 120 uczestników, 880 kwalifikowanych partii, 891 logicznych wpisów rund łącznie z pauzami; f=350, w=289, n=352, u=290.
- Po uzupełnieniu historii poprawiono tylko rozbieżne n/u: 119 z 143 zapisów rankingowych turniejów; 24 zgodne pozostały bez zmian.
- Wznowienie po Strzyżowie oraz po Oświęcimiu/przed Kunicami daje identyczne dane domenowe i semantykę audytu jak przebieg ciągły. Normalizowano jedynie techniczne ID i czas audytu; wersję cache porównywano osobno w tej samej bazie.
- Ponowny import 1399: `unchanged`, zero zmian dokładnej treści danych, audytu i dataset version, zero żądań strony wyników. Kalendarz podano z zachowanej kopii; pełne archiwum wyników pozostało źródłem komendy.
- Zimne/ciepłe odczyty ośmiu ścieżek API (16 odpowiedzi), rankingi bieżące i historyczne, lista turniejów/osób oraz oba scalone profile: poprawne. Nagłówki publicznego cache zachowane. Losowe identyfikatory węzłów JSON-LD normalizowano przy porównywaniu treści.
- Rzeczywiste odczyty przez nginx `localhost:8081` wskazują 12160 w oficjalnym i starym rankingu, odpowiednio 350 i 352 osoby. Przed importem odczyt wskazywał Kraków.
- Pełne odciski danych docelowej bazy po normalizacji technicznych ID są identyczne z kopią akceptacyjną.

## Turnieje i źródła

Hashe dotyczą zachowanych bajtów archiwum; URL każdej pozycji: `https://www.pfs.org.pl/turniej.php?id=<PFS ID>`. Ścieżki plików i metadane pobrania zachowano w manifeście i plikach `tournament_html/*.meta.json`.

| PFS ID | Lokalne ID | Data | Turniej | SHA-256 |
|---|---|---|---|---|
| 1421 | 12142 | 2026-03-14 | VIII Babskie granie (turniej bez jaj) | `f960876eda645db03e2221e92ab93c610fd100cb7f5e93228cee3860d34597cf` |
| 1432 | 12143 | 2026-03-14 | V Mistrzostwa Sochaczewa w Scrabble pod Patronatem Burmistrza Miasta Sochaczew | `c835e5d476ec2ba5da329f18bed432aa3d577478a3c82b01e58f3ab2f4f28965` |
| 1412 | 12144 | 2026-03-28 | XVIII Mistrzostwa Doliny Karpia w Scrabble | `39e8ae218bfc2c6a35095577a4a6346b99905f5bca174a1398b1e52b1ca1672f` |
| 1409 | 12145 | 2026-04-11 | XVII Mistrzostwa Wrocławia w Scrabble o Puchar OMG | `f4d986926ffa7ad0827dceb0ceb90ea73cffc53c3340128519dddab3b14e2d4e` |
| 1423 | 12146 | 2026-04-18 | I Mistrzostwa Mszany Dolnej w Scrabble | `8e91a110ad5f1b7c6e3efdedce3e2a24ba151bc2a250927357b89bf654902d58` |
| 1426 | 12147 | 2026-04-25 | XVII Ogólnopolskie Mistrzostwa Raciborza w Scrabble o Puchar Prezydenta Miasta Jacka Wojciechowicza | `89942191a24f1ee93d667b3823c5321f5d7a15fb2dfa73553d1c7353acbaf488` |
| 1441 | 12148 | 2026-05-09 | VII Ogólnopolskie Mistrzostwa Stargardu w Scrabble | `ba56d2b956ea6c6059c3c5e2fa625d9c590d8960b4ced49ae339799db6ad81d6` |
| 1418 | 12149 | 2026-08-01 | II Mistrzostwa Strzyżowa w Scrabble pod patronatem Burmistrza Strzyżowa | `866d66ffdef471b97ea34b30564f5a0334aca41bea29c07c7e520ef694e9fe73` |
| 1406 | 12150 | 2026-08-22 | IX Otwarte Mistrzostwa Gdańska w Scrabble | `8a9c25fac9f284c8650a6faf84e2943834754501d0968d70a78a2366e5adc896` |
| 1411 | 12151 | 2026-09-05 | VII Otwarte Mistrzostwa Białegostoku o Puchar Rektora Uniwersytetu w Białymstoku | `fda0c440dd153e2f5e6d05633887b936488d3a351610442cc659edbad542bc3c` |
| 1405 | 12152 | 2026-09-12 | V Mistrzostwa Oświęcimia w Scrabble | `188bfd188c5b9afd0e0465b4a67ff4e29bde52f9be0979cb37f2d2ab3e35f96e` |
| 1439 | 12153 | 2026-09-12 | II Turniej o Puchar Wójta Gminy Kunice | `e20c2078e02c8461a5e3eea280135a4e4dc65effe6bb12b8d1287c76a79c8fa4` |
| 1414 | 12154 | 2026-09-19 | XX Jubileuszowe Ogólnopolskie Mistrzostwa Jaworzna w Scrabble | `4258195c4de71a7e9ffb9ce003398db561623d7c1fced0037ed7cc73280aae3d` |
| 1415 | 12155 | 2026-09-26 | VI Otwarte Mistrzostwa Lublina w Scrabble | `2c7b5fea18febbae0116f597ea7abaf6e45bfabb1d2ef4e42dc79e160e94adcf` |
| 1410 | 12156 | 2026-10-03 | XXVIII Mistrzostwa Warmii i Mazur w Scrabble o Puchar Burmistrza Nowego Miasta Lubawskiego | `74306f74eb141dfbe64bbb98f02941dcfc2aa8fbeeca1e5249479256c8ce1158` |
| 1425 | 12157 | 2026-10-17 | XII Mistrzostwa Ziemi Wałeckiej w Scrabble | `c2d72150ea6a837572f3f5451802a78c27d6d88b286d628e0ed30469c9b1998e` |
| 1442 | 12158 | 2026-10-24 | V Mistrzostwa Sandomierza o Puchar Burmistrza | `ffa5d7acdaaa99d26495698077c322c7a046521fbf62c79d043ae1835d64780a` |
| 1451 | 12159 | 2026-11-07 | I Turniej Scrabble "Piętnastka" | `6641e3bef06d58ed3647e586e9f4504af106a80da8d067362769b89ff4e75731` |
| 1399 | 12160 | 2026-11-14 | XXXIII Mistrzostwa Polski - faza eliminacyjna | `3295dee800010d6f26423701b89bbef9bddf29df904209a929abeb79e6b3054e` |

## Tożsamości i historia

- Użytkownik potwierdził: Maria Magdalena Kupczyńska → Magdalena Kupczyńska (68); Michał.Piotr Waszkiewicz (3468) i Michał Waszkiewicz (767) → wspólny profil 767; Zuzanna Adamczewska (337) i Zuzanna Stasiak (5127) → wspólny profil 337 z aktualnym nazwiskiem Stasiak. Przeniesiono powiązania i wyniki, a podsumowania połączono z właściwymi wagami. Brak nakładających się wyników/rankingów sprawdzono przed zapisem.
- Semenov pozostają dwiema osobami. Oficjalna lista zawiera jednocześnie Zuzannę Semenov (118,14; 827/7) i Dorotę Ninę Semenov (107,29; 751/7). Druga odpowiada istniejącemu profilowi Dorota Nina (4158). To realizuje warunek użytkownika dotyczący dowodu z rankingów, bez utożsamiania różnych zawodniczek.
- Grażyna Maria Nowak odpowiada profilowi Grażyna Maria (4235): ten sam występ Wrocław 2024-07-28, 682 skalpy / 7 partii i ranking 97,43.
- Odtwarzalny skrypt korekty to `identity-repair.sql`; mapowania są w `config/pfs_player_aliases.yaml`. Skrypt sprawdza lokalne ID, nazwy i brak konfliktów, działa transakcyjnie pod blokadą organizacji.
- Eliminacje MP 2023–2025 są już częścią historycznych turniejów 30-rundowych (lokalne 9385, 9425, 9467); nie dublowano ich.
- Łomianki PFS 1424 nie mają obecnie wpisu w kalendarzu ani zakończonych wyników na stronie PFS. Zachowano odpowiedź strony; nie wyciągano z niej wniosku o odwołaniu.
- Oświęcim poprzedza Kunice. Oficjalne f/w są identyczne dla weekendu; stare n/u są liczone kolejno, zgodnie z decyzją użytkownika.
- Walkowery 400:0 oraz pauzy zachowano w wynikach, wyłączając je z obliczeń rankingowych. Rozpoznane historyczne podsumowania walkowerów i zaokrąglenia skalpów są jawnie raportowanymi ostrzeżeniami źródła.

## Testy, cache i wydajność

- 69 testów / 669 asercji: wynik poprawny; obejmuje prawdziwe transakcje PostgreSQL, rollback, blokady, tożsamości, diagnostykę parsera, chronologię, wznowienie, publikację cache i API. Konfiguracja DI oraz `git diff --check`: poprawne.
- Szerszy wcześniejszy przebieg serwisów/komend wykazał dwa błędy w niezmienionym `ClubTournamentResultsParserTest`, dotyczące oczekiwań dla pauz. Parser klubowy, test i fixture nie były zmieniane w tym zadaniu. Szczegóły: `var/pfs-import/services-commands-tests.txt`. Nie wyłączano tych kontroli.
- Kontrola wykryła stary skompilowany kontener bez debugowania. Przebudowano kontener i powtórzono pełne próby od dumpa na obu bazach; raportowane odbiory dotyczą powtórzonej sekwencji z aktywnym archiwum i preflight.
- Ustalono stały `framework.cache.prefix_seed` dla środowiska, aby CLI bez debugowania i HTTP z debugowaniem współdzieliły klucz dataset version. Osobny test potwierdza współdzielenie. Dane publikowane są po commit; warmup był wyłączony podczas odbioru. Nie czyszczono globalnie Redis.
- Rekonstrukcja starego rankingu na tej samej historii Krakowa: **253 → 12 zapytań SQL**, mediana ciepłych przebiegów **0,878 → 0,497 s**, identyczny hash pełnego wyniku `43cf9e0bbb77930d96b8d9ada81a41f5100807d150000a32f5475aa3e0438261`. Pamięć szczytowa wzrosła z około 50 do 65 MB. To pomiar tej samej historii przed naprawami danych; nie jest pomiarem przyspieszenia całego importu.
- Niezależny weryfikator odtwarza wiele punktów kontrolnych w jednym przebiegu historii; porządek dat i ID sprawdzają testy integracyjne.

## Dowody

Pliki robocze znajdują się w ignorowanym `var/pfs-import/`:

- `target-results/history-manifest.json`, `target-results/ranking-verification.json`, logi wszystkich 19 importów i `target-results/old-repair.txt`;
- `resume-comparison.json`, `target-results/acceptance-comparison.json`;
- `target-results/retry-verification.json`, dokładne odciski przed/po i licznik pobrań;
- `target-results/api-verification.json`, `target-results/http-*.json`, `target-results/http-*.headers`;
- `identity-resolutions.json`, `source-audit.json`, `source-validation.md`;
- `test-results.txt`, `test-results.md`, `performance-before.json`, `performance-after.json`.

Baza użytkownika została uzupełniona. Próby akceptacyjne i zastosowanie lokalne są zakończone. Nie wykonano wdrożenia na zewnętrzny serwer.
