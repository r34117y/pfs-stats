# Docker on prod

```bash
docker compose -f compose.prod.yaml --env-file .env.local down
docker compose -f compose.prod.yaml --env-file .env.local up -d
```

Po imporcie nowego dumpa MySQL odswiez cache datasetu:

```bash
docker compose -f compose.prod.yaml --env-file .env.local exec -T php php bin/console app:cache:refresh-after-import --env=prod --warmup
```
