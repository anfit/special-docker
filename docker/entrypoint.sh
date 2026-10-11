#!/bin/sh
set -eu

# Session files and cached AWS credentials need a writable home that survives container restarts.
session_path="${SPECIAL_SESSION_PATH:-/var/lib/special/sessions}"
mkdir -p "$session_path"
chown -R www-data:www-data "$(dirname "$session_path")"
chmod 700 "$session_path"

# Optional convenience for local development: create the DynamoDB table when it does not exist yet.
# Never needed in AWS, where the CloudFormation stack owns the table.
if [ "${SPECIAL_AUTO_INIT_TABLE:-0}" = "1" ]; then
    tries=0
    until php /var/www/html/scripts/init-table.php; do
        tries=$((tries + 1))
        if [ "$tries" -ge 15 ]; then
            echo "init-table failed after $tries attempts; continuing so the container stays inspectable" >&2
            break
        fi
        sleep 2
    done
fi

exec "$@"
