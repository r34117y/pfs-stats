# Poprawki po analizie specyfikacji

Data: 2026-11-19. Zakres: zmiany dokumentów zatwierdzone przez użytkownika, bez implementacji i importu.

| Ustalenie | Przyjęta poprawka | Zadania dowodzące poprawności |
|-----------|------------------|-------------------------------|
| U1 HIGH | Manifest oczekiwań pozostaje ważny po przewidzianym dopisaniu; preflight sprawdza DB, korekty unieważniają zależną część | T013–T014, T052 |
| U2 HIGH | Pełne źródło drugiego równoczesnego turnieju może potwierdzić debiutanta; profil/f/w bez przedwczesnej historii n/u | T037, T040–T041 |
| U3 MEDIUM | Surowe sekcje i diagnostyka każdego wiersza towarzyszą DTO; pominięcie jest błędem | T005–T007, T032 |
| C1 MEDIUM | Verify porównuje wszystkie dane turniejowe oraz rankingi, także zmiany przy tych samych countach | T027, T057 |
| C2 MEDIUM | Drugi przebieg od tego samego seed z kontrolowanym przerwaniem i porównaniem semantycznym; no-op osobno dokładny | T043, T058 |

Zachowano 62 identyfikatory zadań, kolejność zależności i 25 wymagań/kryteriów. Nie dodano importera zakresowego ani wspólnego starego rankingu po weekendzie. Wszystkie pięć ustaleń ma jednoznaczną decyzję i zaplanowane testy; potwierdzenie działania wymaga implementacji i wykonania tych testów. Lista jakości specyfikacji pozostaje 16/16.
