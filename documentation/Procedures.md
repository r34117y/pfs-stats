# Credentials

ENV Variables are kept in `.env.local`.

## User operations:

Create a new user:

```bash
php bin/console app:user:create admin@scrabble.local 'Scrabble!2026' \
  --year-of-birth=1990 \
  --player-id=1
```

Change user password:

```bash
php bin/console app:user:change-password admin@scrabble.local 'NewStrongPassword123!'
```
