# Credentials

Zmienne środowiskowe trzymamy w `.env.local` (te z których korzysta docker muszą być w tym pliku).

# Autentykacja (Symfony Security, PostgreSQL `default` connection):

- Encja uzytkownika: `App\Entity\User`
- Tabela auth: `app_user`
- Logowanie endpoint: `POST /login` (form login, endpoint gotowy; sam formularz niezaimplementowany)
- Dla zapytan AJAX/API brak sesji zwraca `401` JSON (bez redirecta na `/login`)

## Utworzenie uzytkownika (haslo jest haszowane przez Symfony):

```bash
php bin/console app:user:create admin@scrabble.local 'Scrabble!2026' \
  --year-of-birth=1990 \
  --player-id=1
```

Tworzenie innego uzytkownika:

```bash
php bin/console app:user:create user@example.com 'StrongPassword123!' --role=ROLE_USER
```

Zmiana hasla istniejacego uzytkownika:

```bash
php bin/console app:user:change-password admin@scrabble.local 'NewStrongPassword123!'
```
