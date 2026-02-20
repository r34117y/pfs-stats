Tworzenie tego projektu:

```bash
composer create-project symfony/skeleton scrabble-stats-api
cd scrabble-stats-api
composer require api
```


Utworzenie bazy danych (w kontenerze mysql). Hasło w compose.yaml

Usunąć starą bazę jeśli trzeba (mysql -u root -p)

```sql
DROP DATABASE m1126_scrabble;
```

```bash
mysql -u root -p < m1126_scrabble.sql 
# Wersja z logiem
mysql -u root -p < m1126_scrabble.sql 2> import.log
# Wyświetlanie progresu
pv m1126_scrabble.sql | mysql -u root -p
```

Wgranie dumpa na produkcję:

```bash
 MYSQL_ROOT_PASSWORD='your-root-pass' bin/sync-db-dump-to-prod.sh --db-name m1126_scrabble
```

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

---

Autentykacja (Symfony Security, PostgreSQL `default` connection):

- Encja uzytkownika: `App\Entity\User`
- Tabela auth: `app_user`
- Logowanie endpoint: `POST /login` (form login, endpoint gotowy; sam formularz niezaimplementowany)
- Dla zapytan AJAX/API brak sesji zwraca `401` JSON (bez redirecta na `/login`)

Po zmianach auth uruchom migracje:

```bash
php bin/console doctrine:migrations:migrate
```

Utworzenie uzytkownika (haslo jest haszowane przez Symfony):

```bash
php bin/console app:user:create admin@scrabble.local 'Scrabble!2026' \
  --year-of-birth=1990 \
  --photo=/uploads/users/admin.jpg \
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

AJAX/Alpine.js:

- Wysylaj zapytania z cookies sesyjnymi: `credentials: 'include'`
- Przy cross-origin wymagane CORS z `allow_credentials: true` (ustawione w `config/packages/nelmio_cors.yaml`)
