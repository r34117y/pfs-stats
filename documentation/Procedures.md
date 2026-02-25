# Credentials

Zmienne środowiskowe trzymamy w `.env.local` (te z których korzysta docker muszą być w tym pliku).

# MySQL

- wysyłka na serwer: `scp -r /home/adam/projects/scrabble-stats-api/db_dump/20260219.sql  ubuntu@54.38.54.56:/var/www/pfs-stats/db_dump/`
- usunąć starą bazę jeśli trzeba:
  - `mysql -u root -p`
  - `DROP DATABASE m1126_scrabble;`
- wgrywanie dumpa
  - `mysql -u root -p < dump.sql`
  - wersja z logiem: `mysql -u root -p < dump.sql 2> import.log`
  - pasek postępu: `pv dump.sql | mysql -u root -p`
  - po imporcie zaktualizować wersję dataset cache:
    - `php bin/console app:dataset-version:bump`

Uzycie drugiego polaczenia MySQL (domyslnie aplikacja i migracje sa na PostgreSQL):

```php
<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MysqlReader
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private readonly Connection $mysqlConnection,
    ) {
    }

    public function fetchSampleRows(int $limit = 10): array
    {
        return $this->mysqlConnection->fetchAllAssociative(
            'SELECT * FROM games ORDER BY id DESC LIMIT :limit',
            ['limit' => $limit],
            ['limit' => \PDO::PARAM_INT]
        );
    }
}
```

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
