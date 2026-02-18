Tworzenie tego projektu:

```bash
composer create-project symfony/skeleton scrabble-stats-api
cd scrabble-stats-api
composer require api
```


Utworzenie bazy danych (w kontenerze mysql). Hasło w compose.yaml

```bash
mysql -u root -p < m1126_scrabble.sql 
```

Wgranie dumpa na produkcję:

```bash
 MYSQL_ROOT_PASSWORD='your-root-pass' bin/sync-db-dump-to-prod.sh --db-name m1126_scrabble
```

