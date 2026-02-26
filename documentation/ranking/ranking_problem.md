# Problem

W Polskiej Federacji Scrabble warunkiem bycia uwzględnionym w rankingu jest rozegranie co najmniej 30 partii w okresie 2 lat (gry starsze niż 2 lata nie są uwzględniane w rankingu). Ze względu na losowy element scrabble zdarzają się sytuacje, gdy gracze mieli nieco więcej szczęścia przez 3 turnieje z rzędu (turnieje są zwykle 12-rundowe, choć zdarzają się inne) i zajmują bardzo wysoką lokatę w rankingu z rozegranymi trzydziestoma kilkoma partiami. Ten zawyżony ranking może utrzymywać się przez dwa lata, a poszkodowani są gracze, którzy grają regularnie, a których rankingi są bardziej uśrednione (ze względu na czynnik losowy, czyli dobór partii z worka). Posiadam bazę danych z wynikami wszystkich rozgrywek, które odbywały się w ciągu kilkunastu ostatnich lat (prawie 1000 turniejów i ok. 300000 partii). Zaproponuj analityczną (najlepiej algorytmiczną) metodę ustalenia sensownej minimalnej liczby rozegranych partii potrzebną do bycia uwzględnionym w rankingu, tak żeby zminimalizować sytuacje opisane powyżej (najlepiej z zadanym z góry pożądanym stopniem pewności: 95%, 99% etc.)

# Proponowane rozwiązania

Da się to ugryźć bardzo „inżyniersko”: zamiast ustalać próg typu 30 gier, ustalasz próg typu „dopóki nie mam wystarczająco małej niepewności oceny siły gracza, nie pokazuję go w rankingu (albo pokazuję, ale jako ‘prowizoryczny’)”. A minimalna liczba gier wychodzi z danych i z żądanego poziomu pewności (95%, 99%).

Poniżej masz 3 podejścia — od najbardziej „rankingowego” (praktyczne) do najbardziej „modelowego” (mocno statystyczne). Wszystkie są algorytmiczne i dają się policzyć na Twojej bazie ~300k partii.

## Podejście A (najpraktyczniejsze): próg nie gier, tylko niepewności (RD) jak w Glicko-2

### Idea

w Scrabble losowość (worek) powoduje, że po małej liczbie gier ocena siły ma duży błąd. Systemy typu Glicko / Glicko-2 trzymają obok ratingu także RD (rating deviation) — miarę niepewności. RD spada wraz z liczbą i „jakością” gier (siła rywali).

### Reguła kwalifikacji do rankingu:

- Gracz jest “w rankingu”, jeśli jego RD ≤ RD_max.
- RD_max dobierasz tak, by spełnić żądany poziom pewności (np. 95%/99%) dla ryzyka „fałszywego topu”.

### Jak dobrać RD_max algorytmicznie z danych (i to jest Twoje „95% / 99%”)

- Liczysz rating + RD dla wszystkich graczy w kroczących oknach 2-letnich (dokładnie jak w regulaminie).
- Dla każdego okna i każdego gracza bierz:
  - jego stan po n pierwszych grach w oknie (rating_n, RD_n)
  - oraz „późniejszą” ocenę po dużej liczbie gier w tym samym oknie (np. po n=120 albo po wszystkich dostępnych) jako proxy prawdziwej siły w tym oknie.
- Definiujesz zdarzenie „zawyżenia” (przykład):
  - gracz był w Top-K (np. Top 20 / Top 50) po n≈30–40 grach,
  - ale po dużej liczbie gier spadł poza Top-K o co najmniej M miejsc lub o co najmniej Δ ratingu.
- Teraz dobierasz RD_max tak, by:
  - P(zawyżenie | RD ≤ RD_max) ≤ 5% (dla 95%) albo ≤ 1% (dla 99%).

Efekt uboczny (pozytywny): to nie będzie “sztywne 30/50/80”, tylko próg adaptacyjny — ktoś kto gra dużo z silnymi rywalami szybciej „stabilizuje się” niż ktoś z małą liczbą gier z losowymi przeciwnikami.

### Co dostajesz w praktyce:

- „Minimalna liczba gier” wychodzi jako rozkład: np. mediana 55, 90-percentyl 85 itd. (dla RD_max dobranego na 95%).
- Możesz to potem spłaszczyć do prostego progu (np. „zwykle ok. 70 gier”), ale lepsze jest RD jako warunek oficjalny.

## Podejście B: „próg n” wyznaczony przez kontrolę odsetka fałszywych liderów (symulacja na danych)

Jeśli koniecznie chcesz jedną liczbę „min. gier”, to możesz ją policzyć tak, żeby w danych historycznych spełniała warunek typu 95%/99%.

### Definicja problemu (proponowana):

„Fałszywy top” = gracz, który po n grach jest w Top-K, ale jego „stabilna” pozycja (po dużej liczbie gier w oknie) nie jest w Top-K.

### Algorytm:

- Tworzysz wszystkie kroczące okna 2-letnie (np. co tydzień/miesiąc).
- Dla każdego okna:
  - wyznaczasz „stabilny ranking” licząc rating tylko na podstawie graczy z dużą liczbą gier (albo z RD ≤ małe).
- Dla kandydującego progu n = 30..200:
  - w każdym oknie tworzysz „ranking wczesny” używając tylko pierwszych n gier każdego gracza (w tym oknie),
  - mierzysz odsetek przypadków, w których ktoś wczesny Top-K nie jest stabilnie w Top-K.
- Wybierasz najmniejsze n takie, że:
  - średnio (lub w 95-percentylu po oknach) odsetek fałszywych Top-K ≤ 5% (albo 1%).

To jest bardzo czytelne dla środowiska:
„Ustawiamy próg tak, aby w 99% przypadków zawodnik pojawiający się w Top-50 nie był ‘artefaktem 30 gier’.”

### Warianty ulepszające":
- Zamiast „Top-K”, użyj „przeskok o ≥X miejsc” lub „różnica ratingu ≥Δ”.
- Zamiast „pierwszych n gier” (które mogą być skorelowane turniejowo), użyj losowych podpróbek n gier z okna (bootstrap), żeby mierzyć czysty efekt liczby partii.

## Podejście C (najbardziej „statystyczne”): model siły + przedziały ufności i próg na szerokości CI

Tu jawnie modelujesz losowość Scrabble w wyniku.

### Model (prosty i skuteczny): Bradley–Terry / Elo-logit

- Każdy gracz ma latentną siłę s_i
- Prawdopodobieństwo wygranej z j: P(i wygrywa z j) = σ(s_i - s_j), gdzie σ to funkcja logistyczna.
- To jest standard dla gier 1v1. Możesz dorzucić „czynnik turniejowy” albo „home/away” jeśli masz.

### Kryterium kwalifikacji (95%/99%)

- Liczysz posterior (Bayes) lub aproksymację normalną (MLE + macierz informacji).
- Dostajesz dla gracza przedział ufności na s_i: CI_95(s_i)
- Żeby być w rankingu, wymagaj np.:
  - szerokość CI ≤ W_max, albo
  - P(s_i > s_\text{median}) ≥ 0.99 (jeśli chcesz pewność bycia powyżej pewnego poziomu),
  - albo dla Top-K: P(i należy do Top-K) ≥ 0.95 (to już liczysz przez Monte Carlo z posteriorów).

### Jak z tego wyciągnąć minimalne n

- Z Twoich danych estymujesz relację: liczba gier n → typowa szerokość CI.
- Minimalne n to najmniejsze, dla którego (np. w 95-percentylu graczy) CI jest wystarczająco wąskie.

To jest bardziej „naukowe”, ale komunikacyjnie trudniejsze. Za to najlepiej kontroluje „szczęście w 3 turniejach”.
