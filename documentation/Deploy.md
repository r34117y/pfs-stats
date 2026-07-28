# Production deploy

Production uses `compose.prod.yaml` with `.env.local`.

Do not deploy with only:

```bash
docker compose -f compose.prod.yaml --env-file .env.local up -d
```

That command starts or reconciles existing containers, but it is not a full
application deploy. After `git pull`, changes can remain invisible because:

- Symfony runs with `APP_ENV=prod` and keeps compiled container/cache files in
  the `symfony_cache_data` Docker volume.
- The `nginx` production image copies `public/` at image build time, so changes
  to public assets or nginx config require an image rebuild.
- If `composer.lock` changed, the host-mounted application directory must have
  dependencies updated because `./:/var/www/html` hides the `vendor/` directory
  installed inside the PHP image.

## Standard deploy

From the production checkout:

```bash
git pull
bin/deploy-prod.sh
```

`bin/deploy-prod.sh` runs:

- `docker compose ... up -d --build --remove-orphans`
- Symfony `cache:clear --env=prod`
- Symfony `cache:warmup --env=prod`
- Doctrine migrations

Use `--no-migrate` only when the deploy must not run migrations:

```bash
bin/deploy-prod.sh --no-migrate
```

Use `--no-build` only for a config/data-only restart where no PHP image,
`public/`, or nginx image changes need to be rebuilt:

```bash
bin/deploy-prod.sh --no-build
```

## Composer changes

If `composer.lock` changed after `git pull`, update dependencies in the mounted
application directory before warming cache:

```bash
docker compose -f compose.prod.yaml --env-file .env.local exec -T php composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
bin/deploy-prod.sh --no-build
```

If the containers are not running yet, start them first:

```bash
docker compose -f compose.prod.yaml --env-file .env.local up -d --build
docker compose -f compose.prod.yaml --env-file .env.local exec -T php composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
bin/deploy-prod.sh --no-build
```

## Environment

The default deploy script reads:

- compose file: `compose.prod.yaml`
- env file: `.env.local`

`.env.local` must define at least:

- `APP_SECRET`
- `POSTGRES_PASSWORD`

Override them only when deploying a different stack:

```bash
COMPOSE_FILE=compose.prod.yaml ENV_FILE=.env.local bin/deploy-prod.sh
```
