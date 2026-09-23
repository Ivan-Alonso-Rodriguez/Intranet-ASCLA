#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
mkdir -p coverage test-results

# Evita choques entre qa y uat si ambos jobs usan el mismo host Jenkins.
case "${BRANCH_NAME:-ci}" in
  qa)
    export ASCLA_HTTP_PORT=18088
    export ASCLA_MAILPIT_PORT=18025
    ;;
  uat)
    export ASCLA_HTTP_PORT=28088
    export ASCLA_MAILPIT_PORT=28025
    ;;
  *)
    export ASCLA_HTTP_PORT=38088
    export ASCLA_MAILPIT_PORT=38025
    ;;
esac

SAFE_BRANCH="$(printf '%s' "${BRANCH_NAME:-ci}" | tr -cs '[:alnum:]_-' '-')"
export COMPOSE_PROJECT_NAME="ascla_ci_${SAFE_BRANCH}"

random_secret() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 24
  else
    cat /proc/sys/kernel/random/uuid | tr -d '-'
  fi
}

cat > .env <<EOF_ENV
ASCLA_DB_HOST=db
ASCLA_DB_NAME=wordpress
ASCLA_DB_USER=wordpress
ASCLA_DB_PASSWORD=$(random_secret)
ASCLA_DB_ROOT_PASSWORD=$(random_secret)
ASCLA_ADMIN_PASSWORD=$(random_secret)
ASCLA_DEMO_PASSWORD=$(random_secret)
ASCLA_PROFILE_DEMO_PASSWORD=$(random_secret)
ASCLA_HTTP_PORT=${ASCLA_HTTP_PORT}
ASCLA_MAILPIT_PORT=${ASCLA_MAILPIT_PORT}
ASCLA_SITE_URL=http://localhost:${ASCLA_HTTP_PORT}
ASCLA_SITE_TITLE=ASCLA CI
ASCLA_ADMIN_USER=ascla.admin
ASCLA_ADMIN_EMAIL=admin@example.invalid
ASCLA_WP_ENVIRONMENT_TYPE=local
ASCLA_LOCAL_MAIL_CAPTURE=true
ASCLA_SEED_DATA=true
EOF_ENV

cleanup() {
  docker compose down -v --remove-orphans >/dev/null 2>&1 || true
  rm -f .env
}
trap cleanup EXIT

echo 'Levantando WordPress desechable para pruebas...'
docker compose up -d db mailpit wordpress

echo 'Esperando e inicializando WordPress/ASCLA...'
INSTALADO=no
for i in $(seq 1 24); do
  if docker compose run --rm cli \
      sh /opt/ascla-scripts/init-wordpress.sh \
      >/dev/null 2>&1; then
    INSTALADO=si
    break
  fi
  echo "  intento $i/24"
  sleep 5
done

if [ "$INSTALADO" != "si" ]; then
  echo 'ERROR: no se pudo inicializar el WordPress de pruebas.'
  docker compose ps -a || true
  docker compose logs --tail=80 --no-color || true
  exit 1
fi

docker compose run --rm cli wp ascla seed

echo 'Preparando PHPUnit y PCOV...'
docker compose exec -T -u 0 wordpress sh -c '
  set -e
  if ! command -v curl >/dev/null 2>&1; then
    apt-get update
    apt-get install -y --no-install-recommends curl ca-certificates
  fi
  if ! php -m | grep -qi "^pcov$"; then
    apt-get update
    apt-get install -y --no-install-recommends $PHPIZE_DEPS
    pecl install pcov
    docker-php-ext-enable pcov
  fi
  test -f /tmp/phpunit.phar || curl -fsSL https://phar.phpunit.de/phpunit-11.phar -o /tmp/phpunit.phar
'

echo 'Ejecutando PHPUnit con cobertura para SonarQube...'
docker compose exec -T wordpress php \
  -d pcov.enabled=1 \
  -d pcov.directory=/var/www/html/wp-content/plugins/ascla-core/src \
  /tmp/phpunit.phar \
  --bootstrap /opt/ascla-tests/bootstrap.php \
  --coverage-filter /var/www/html/wp-content/plugins/ascla-core/src \
  --coverage-clover /tmp/ascla-clover.xml \
  --coverage-text=/tmp/ascla-coverage.txt \
  --log-junit /tmp/ascla-junit.xml \
  /opt/ascla-tests/php

docker compose cp wordpress:/tmp/ascla-clover.xml coverage/clover.xml
docker compose cp wordpress:/tmp/ascla-coverage.txt coverage/php-summary.txt
docker compose cp wordpress:/tmp/ascla-junit.xml coverage/junit.xml

# Sonar debe ver rutas relativas al workspace, no rutas internas del contenedor.
sed -i 's#/var/www/html/wp-content/plugins/ascla-core/#wp-content/plugins/ascla-core/#g' coverage/clover.xml
sed -i 's#/opt/ascla-tests/#tests/#g' coverage/junit.xml

echo 'Pruebas finalizadas. Reportes disponibles en coverage/.'
