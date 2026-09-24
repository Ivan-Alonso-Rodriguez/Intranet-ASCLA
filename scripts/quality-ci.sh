#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
mkdir -p coverage test-results

# Each build has its own database, volumes and DNS alias on proxy_net.
SAFE_BRANCH="$(printf '%s' "${BRANCH_NAME:-ci}" | tr -cs '[:alnum:]_-' '-')"
export COMPOSE_PROJECT_NAME="ascla_ci_${SAFE_BRANCH}_${BUILD_NUMBER:-local}"
CI_ENV_FILE="$(mktemp "$ROOT/.env.ci.XXXXXX")"
chmod 600 "$CI_ENV_FILE"
dc() { docker compose --env-file "$CI_ENV_FILE" "$@"; }
cleanup() {
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  rm -f "$CI_ENV_FILE"
}
trap cleanup EXIT
docker network inspect proxy_net >/dev/null

random_secret() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 24
  else
    cat /proc/sys/kernel/random/uuid | tr -d '-'
  fi
}

cat > "$CI_ENV_FILE" <<EOF_ENV
ASCLA_DB_HOST=db
ASCLA_DB_NAME=wordpress
ASCLA_DB_USER=wordpress
ASCLA_DB_PASSWORD=$(random_secret)
ASCLA_DB_ROOT_PASSWORD=$(random_secret)
ASCLA_ADMIN_PASSWORD=$(random_secret)
ASCLA_DEMO_PASSWORD=$(random_secret)
ASCLA_PROFILE_DEMO_PASSWORD=$(random_secret)
ASCLA_SITE_URL=http://${COMPOSE_PROJECT_NAME}-wordpress
ASCLA_SITE_TITLE=ASCLA CI
ASCLA_ADMIN_USER=ascla.admin
ASCLA_ADMIN_EMAIL=admin@example.invalid
ASCLA_WP_ENVIRONMENT_TYPE=local
ASCLA_LOCAL_MAIL_CAPTURE=true
ASCLA_SEED_DATA=true
EOF_ENV

echo 'Levantando WordPress desechable para pruebas...'
dc up -d db mailpit wordpress cron

echo 'Esperando e inicializando WordPress/ASCLA...'
INSTALADO=no
for i in $(seq 1 24); do
  if dc run --rm cli \
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
  dc ps -a || true
  dc logs --tail=80 --no-color || true
  exit 1
fi

dc run --rm cli wp ascla seed

echo 'Preparando PHPUnit y PCOV...'
dc exec -T -u 0 wordpress sh -c '
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
dc exec -T wordpress php \
  -d pcov.enabled=1 \
  -d pcov.directory=/var/www/html/wp-content/plugins/ascla-core \
  /tmp/phpunit.phar \
  --bootstrap /opt/ascla-tests/bootstrap.php \
  --coverage-filter /var/www/html/wp-content/plugins/ascla-core \
  --coverage-clover /tmp/ascla-clover.xml \
  --coverage-text=/tmp/ascla-coverage.txt \
  --log-junit /tmp/ascla-junit.xml \
  /opt/ascla-tests/php

dc cp wordpress:/tmp/ascla-clover.xml coverage/clover.xml
dc cp wordpress:/tmp/ascla-coverage.txt coverage/php-summary.txt
dc cp wordpress:/tmp/ascla-junit.xml coverage/junit.xml

# Sonar debe ver rutas relativas al workspace, no rutas internas del contenedor.
sed -i 's#/var/www/html/wp-content/plugins/ascla-core/#wp-content/plugins/ascla-core/#g' coverage/clover.xml
sed -i 's#/opt/ascla-tests/#tests/#g' coverage/junit.xml

printf '%s' '{"action":"setup"}' | dc exec -T wordpress php /opt/ascla-tests/statistics-fixture.php > test-results/statistics-fixture.json

echo 'Ejecutando pruebas E2E y cobertura JavaScript para SonarQube...'
PLAYWRIGHT_IMAGE="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.62.1-noble}"
docker run --rm --ipc=host \
  --network proxy_net \
  -e ASCLA_SITE_URL="http://${COMPOSE_PROJECT_NAME}-wordpress" \
  -e ASCLA_ENV_FILE="/work/$(basename "$CI_ENV_FILE")" \
  -e ASCLA_E2E_EPHEMERAL=1 \
  -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  -v "$ROOT:/work:ro" \
  -v "$ROOT/coverage:/work/coverage" \
  -v "$ROOT/test-results:/work/test-results" \
  -w /work \
  "$PLAYWRIGHT_IMAGE" \
  bash -lc '
    set -e
    mkdir -p /tmp/ascla-node
    cp /work/package.json /work/pnpm-lock.yaml /tmp/ascla-node/
    cd /tmp/ascla-node
    corepack pnpm install --frozen-lockfile --ignore-scripts
    cd /work
    export NODE_PATH=/tmp/ascla-node/node_modules
    node tests/e2e.cjs
    node scripts/verify-coverage.cjs
  '

if [ ! -s coverage/lcov.info ] || ! grep -q '^SF:' coverage/lcov.info; then
  echo 'ERROR: no se genero un coverage/lcov.info valido.'
  exit 1
fi

echo 'Pruebas finalizadas. Reportes PHP y JavaScript disponibles en coverage/.'
