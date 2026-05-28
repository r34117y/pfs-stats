#!/usr/bin/env bash

set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-compose.prod.yaml}"
ENV_FILE="${ENV_FILE:-.env.local}"
RUN_MIGRATIONS=true
SKIP_BUILD=false

usage() {
  cat <<'EOF'
Usage:
  bin/deploy-prod.sh [options]

Options:
  --no-migrate  Skip doctrine migrations after containers are up
  --no-build    Skip image rebuild
  -h, --help    Show this help

Required values in ENV_FILE (expected by compose.prod.yaml):
  APP_SECRET
  MYSQL_ROOT_PASSWORD
  POSTGRES_PASSWORD

Optional environment variables:
  MYSQL_USER
  MYSQL_DATABASE
  COMPOSE_FILE (default: compose.prod.yaml)
  ENV_FILE (default: .env.local)
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --no-migrate)
      RUN_MIGRATIONS=false
      shift
      ;;
    --no-build)
      SKIP_BUILD=true
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required but not installed." >&2
  exit 1
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing environment file: $ENV_FILE" >&2
  exit 1
fi

COMPOSE=(docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE")

echo "Starting deployment with ${COMPOSE_FILE} and ${ENV_FILE} ..."

if [[ "$SKIP_BUILD" == true ]]; then
  "${COMPOSE[@]}" up -d --remove-orphans
else
  "${COMPOSE[@]}" up -d --build --remove-orphans
fi

echo "Warming Symfony cache ..."
"${COMPOSE[@]}" exec -T -u www-data php php bin/console cache:clear --env=prod
"${COMPOSE[@]}" exec -T -u www-data php php bin/console cache:warmup --env=prod

if [[ "$RUN_MIGRATIONS" == true ]]; then
  echo "Running Doctrine migrations ..."
  "${COMPOSE[@]}" exec -T -u www-data php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

echo "Deployment finished."
