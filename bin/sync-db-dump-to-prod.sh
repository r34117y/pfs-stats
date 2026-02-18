#!/usr/bin/env bash

set -euo pipefail

HOST="${HOST:-54.38.54.56}"
SSH_USER="${SSH_USER:-ubuntu}"
CONTAINER="${CONTAINER:-pfs-stats-mysql-1}"
DUMP_FILE="${DUMP_FILE:-}"
DB_NAME="${DB_NAME:-}"

usage() {
  cat <<'EOF'
Usage:
  bin/sync-db-dump-to-prod.sh [options]

Options:
  --dump-file PATH     Local .sql dump path (default: latest file from db_dump/*.sql)
  --db-name NAME       Database name to create/import into
  --host HOST          SSH host (default: 54.38.54.56)
  --user USER          SSH user (default: ubuntu)
  --container NAME     MySQL container name (default: pfs-stats-mysql-1)
  -h, --help           Show this help

Required environment variable:
  MYSQL_ROOT_PASSWORD  Root password used inside the MySQL container

Examples:
  MYSQL_ROOT_PASSWORD='secret' bin/sync-db-dump-to-prod.sh --db-name pfs_stats
  MYSQL_ROOT_PASSWORD='secret' bin/sync-db-dump-to-prod.sh --dump-file db_dump/m1126_scrabble.sql --db-name m1126_scrabble
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dump-file)
      DUMP_FILE="${2:-}"
      shift 2
      ;;
    --db-name)
      DB_NAME="${2:-}"
      shift 2
      ;;
    --host)
      HOST="${2:-}"
      shift 2
      ;;
    --user)
      SSH_USER="${2:-}"
      shift 2
      ;;
    --container)
      CONTAINER="${2:-}"
      shift 2
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

if [[ -z "${MYSQL_ROOT_PASSWORD:-}" ]]; then
  echo "MYSQL_ROOT_PASSWORD is required." >&2
  exit 1
fi

if [[ -z "$DUMP_FILE" ]]; then
  DUMP_FILE="$(ls -1t db_dump/*.sql 2>/dev/null | head -n1 || true)"
fi

if [[ -z "$DUMP_FILE" || ! -f "$DUMP_FILE" ]]; then
  echo "Dump file not found. Use --dump-file PATH or place a .sql file in db_dump/." >&2
  exit 1
fi

if [[ -z "$DB_NAME" ]]; then
  DB_NAME="$(basename "$DUMP_FILE" .sql)"
fi

REMOTE_SQL_PATH="/tmp/$(basename "$DUMP_FILE")"

echo "Copying dump to ${SSH_USER}@${HOST}:${REMOTE_SQL_PATH} ..."
scp "$DUMP_FILE" "${SSH_USER}@${HOST}:${REMOTE_SQL_PATH}"

echo "Creating database and importing dump in container ${CONTAINER} ..."
MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASSWORD" ssh "${SSH_USER}@${HOST}" /bin/bash -s -- "$CONTAINER" "$DB_NAME" "$REMOTE_SQL_PATH" <<'EOF'
set -euo pipefail

CONTAINER="$1"
DB_NAME="$2"
REMOTE_SQL_PATH="$3"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed on remote host." >&2
  exit 1
fi

docker ps --format '{{.Names}}' | grep -qx "$CONTAINER" || {
  echo "Container '$CONTAINER' is not running." >&2
  exit 1
}

docker cp "$REMOTE_SQL_PATH" "$CONTAINER:/tmp/import.sql"

docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" "$CONTAINER" \
  mysql -uroot -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" "$CONTAINER" \
  sh -lc "mysql -uroot \"$DB_NAME\" < /tmp/import.sql"

docker exec "$CONTAINER" rm -f /tmp/import.sql
rm -f "$REMOTE_SQL_PATH"
EOF

echo "Done. Database '${DB_NAME}' is ready in container '${CONTAINER}'."
