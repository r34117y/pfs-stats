Tworzenie tego projektu:

```bash
composer create-project symfony/skeleton scrabble-stats-api
cd scrabble-stats-api
composer require api
```


Utworzenie bazy danych (w kontenerze mysql). Hasło w compose.yaml

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
