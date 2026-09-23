#!/usr/bin/env sh
set -eu

: "${ASCLA_SITE_URL:?ASCLA_SITE_URL no esta definido}"
: "${ASCLA_SITE_TITLE:?ASCLA_SITE_TITLE no esta definido}"
: "${ASCLA_ADMIN_USER:?ASCLA_ADMIN_USER no esta definido}"
: "${ASCLA_ADMIN_EMAIL:?ASCLA_ADMIN_EMAIL no esta definido}"
: "${ASCLA_ADMIN_PASSWORD:?ASCLA_ADMIN_PASSWORD no esta definido}"

if ! wp core is-installed --quiet; then
  echo "Instalando WordPress en ${ASCLA_SITE_URL}..."
  wp core install \
    --url="$ASCLA_SITE_URL" \
    --title="$ASCLA_SITE_TITLE" \
    --admin_user="$ASCLA_ADMIN_USER" \
    --admin_password="$ASCLA_ADMIN_PASSWORD" \
    --admin_email="$ASCLA_ADMIN_EMAIL" \
    --skip-email
fi

wp eval-file /opt/ascla-scripts/install-local.php
