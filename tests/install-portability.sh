#!/bin/sh
set -eu
if ! wp core is-installed --quiet; then
    wp core install --url=http://localhost:8089 --title="ASCLA ZIP Test" --admin_user=ascla.admin --admin_email=admin@example.invalid --admin_password="$ASCLA_ADMIN_PASSWORD" --skip-email
fi
