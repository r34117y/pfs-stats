#!/bin/sh
set -eu

USER_UPLOAD_DIR="${USER_PHOTO_UPLOAD_DIR:-/var/www/html/public/uploads/user-photos}"
GAME_UPLOAD_DIR="${GAME_PHOTO_UPLOAD_DIR:-/var/www/html/public/uploads/game-photos}"

mkdir -p "$USER_UPLOAD_DIR" "$GAME_UPLOAD_DIR"

if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data "$USER_UPLOAD_DIR" "$GAME_UPLOAD_DIR"
    chmod 0775 "$USER_UPLOAD_DIR" "$GAME_UPLOAD_DIR"
fi

exec docker-php-entrypoint "$@"
