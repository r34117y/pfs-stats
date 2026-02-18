#!/usr/bin/env bash

set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-compose.prod.yaml}"
RUN_MIGRATIONS=true
SKIP_BUILD=true

usage() {
  cat <<'EOF'
Usage:
  bin/deploy-prod.sh [options]

Options:
  --migrate     Run doctrine migrations after containers are up
  --no-build    Skip image rebuild
  -h, --help    Show this help

Required environment variables (expected by compose.prod.yaml):
  APP_SECRET
  MYSQL_ROOT_PASSWORD
  MYSQL_PASSWORD

Optional environment variables:
  MYSQL_USER
  MYSQL_DATABASE
  COMPOSE_FILE (default: compose.prod.yaml)
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --migrate)
      RUN_MIGRATIONS=true
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

for required_var in APP_SECRET MYSQL_ROOT_PASSWORD MYSQL_PASSWORD; do
  if [[ -z "${!required_var:-}" ]]; then
    echo "Missing required environment variable: $required_var" >&2
    exit 1
  fi
done

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required but not installed." >&2
  exit 1
fi

echo "Starting deployment with ${COMPOSE_FILE} ..."

if [[ "$SKIP_BUILD" == true ]]; then
  docker compose -f "$COMPOSE_FILE" --env-file .env.local up -d --remove-orphans
else
  docker compose -f "$COMPOSE_FILE" --env-file .env.local up -d --build --remove-orphans
fi

echo "Warming Symfony cache ..."
docker compose -f "$COMPOSE_FILE" exec -T php php bin/console cache:clear --env=prod
docker compose -f "$COMPOSE_FILE" exec -T php php bin/console cache:warmup --env=prod

if [[ "$RUN_MIGRATIONS" == true ]]; then
  echo "Running Doctrine migrations ..."
  docker compose -f "$COMPOSE_FILE" exec -T php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

echo "Deployment finished."
