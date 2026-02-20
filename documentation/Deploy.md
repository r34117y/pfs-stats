# Docker on prod

```bash
docker compose -f compose.prod.yaml --env-file .env.local down
docker compose -f compose.prod.yaml --env-file .env.local up -d
```
