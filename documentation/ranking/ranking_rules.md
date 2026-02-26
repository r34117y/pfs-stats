# Metoda Hollingtona

Do rankingu zalicza się wszystkie partie rozegrane podczas oficjalnych turniejów Scrabble w ciągu ostatnich dwóch lat. Aby znaleźć się na liście rankingowej, należy mieć rozegrane w tym okresie przynajmniej 30 partii. Zawodnikom nie posiadającym jeszcze rankingu przydziela się na czas trwania turnieju ranking tymczasowy równy 100.

Ranking to suma punktów pomocniczych, zdobytych na przeciwnikach (punkty te żargonowo zwane są „skalpami”), podzielona przez liczbę rozegranych partii.

„Skalpem” zawodnika z rozegranej partii jest ranking przeciwnika:
powiększony o 50 w przypadku zwycięstwa;
pomniejszony o 50 w przypadku porażki;
nie zmieniony w przypadku remisu.

Załóżmy, że gracz X ma ranking 113, a gracz Y ma ranking 103. Spotykają się ze sobą na turnieju.
Jeżeli wygra X, to jego „skalp” z tej partii wyniesie 153 (103 + 50), a „skalp” gracza Y wyniesie 63 (113 - 50).
Gdy wygra Y, to jego „skalp” wyniesie 163 (113 + 50), a „skalp” gracza X wyniesie 53 (103 - 50).
Gdy padnie remis, to „skalp” gracza X wyniesie 103, a „skalp” gracza Y wyniesie 113.

Zasadę tę modyfikuje się w jednym przypadku — gdy różnica rankingów między przeciwnikami przekracza 50, a zwycięży gracz z wyższym rankingiem. Załóżmy, że gracz X ma ranking 155, a gracz Y ma ranking 100. Gdyby nie zmodyfikowano zasady generalnej, to w przypadku zwycięstwa gracza X, jego „skalp” wyniósłby 150 (100 + 50), zatem poniósłby on stratę mimo zwycięstwa, natomiast „skalp” gracza Y wyniósłby 105 (155 - 50), czyli zanotowałby on zysk mimo porażki. Modyfikacja zasady generalnej polega na tym, że w takim przypadku „skalpami” obu graczy są ich własne rankingi (dla gracza X - 155, a dla gracza Y - 100).

Ranking zaokrągla się do najbliższej liczby całkowitej (dokładne 5/10 zakrągla się w górę). Jedynie dla potrzeb ustalenia kolejności na liście rankingowej porównuje się dokładne, nie zaokrąglone liczby.

Ranking publikowany jest zawsze z datą na dzień po zakończonym turnieju.

# Poprawki PFS-u

- dwuletni okres naliczania rankingu jest weryfikowany na bieżąco
- pod uwagę brane są turnieje, w których gracz uzyskał najwyższy ranking (liczba skalpów/liczba partii), maksymalnie z 200 partii. Jeśli liczba partii przekracza 200, odrzucane są kolejno turnieje z najniższym osiągniętym rankingiem (liczba skalpów/liczba partii) danego gracza do momentu aż liczba rozegranych partii po raz pierwszy nie przekroczy 200.
- zawodnikom, którzy mają ranking niższy od 100, przydziela się na czas trwania turnieju ranking tymczasowy równy 100 (podobnie, jak debiutantom)
- zawodnikom, którzy w ciągu swojej kariery rozegrali co najmniej 30 gier rankingowych, ale nie znajdują się na aktualnej liście rankingowej, na czas turnieju nadaje się ranking turniejowy złożony z takiej liczby ostatnich rozegranych turniejów, aby suma gier rozegranych w ich ramach wyniosła co najmniej 30.
- zawodnikom, którzy w ciągu swojej kariery rozegrali choć 1 grę rankingową, lecz suma rozegranych partii wynosi mniej niż 30, nadaje się tymczasowy ranking turniejowy, powstały na bazie doliczenia skalpów o wysokości 100 tyle razy ile brakuje gier do 30 np. Zawodnikowi X, który rozegrał dotychczas 24 gry rankingowe i osiągnął z nich ranking 120, zatem posiada skalp o wysokości 2880, dolicza się zdobycz skalpową o wysokości 600 (za każdą grę brakującą do 30 gier skalp 100, czyli 6*100) i na czas następnego turnieju będzie on rozstawiony z rankingiem tymczasowym 116 (2880+600 = 3480/30).
