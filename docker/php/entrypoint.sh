#!/bin/sh
set -eu

UPLOAD_DIR="${USER_PHOTO_UPLOAD_DIR:-/var/www/html/public/uploads/user-photos}"

mkdir -p "$UPLOAD_DIR"

if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data "$UPLOAD_DIR"
    chmod 0775 "$UPLOAD_DIR"
fi

exec docker-php-entrypoint "$@"
