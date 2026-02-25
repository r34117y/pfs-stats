# Docker on prod

`compose.prod.yaml` now mounts the project directory into the `php` container (`.:/var/www/html`),
so code changes from `git pull` on host are visible in container immediately.

If this is first run after enabling bind mount, recreate containers once:

```bash
docker compose -f compose.prod.yaml --env-file .env.local up -d --force-recreate
```

Then standard deploy/update flow:

```bash
git pull
docker compose -f compose.prod.yaml --env-file .env.local up -d
```

Po imporcie nowego dumpa MySQL odswiez cache datasetu:

```bash
docker compose -f compose.prod.yaml --env-file .env.local exec -T php php bin/console app:cache:refresh-after-import --env=prod --warmup
```
