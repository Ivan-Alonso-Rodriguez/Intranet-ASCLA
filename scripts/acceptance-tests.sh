#!/usr/bin/env bash
# Manual integration/API E2E/UI E2E against an explicitly selected test environment.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
MODE="${ASCLA_ACCEPTANCE_MODE:-ephemeral}"
RUN="$(date -u +%Y%m%dT%H%M%SZ)-$$"
OUT="$ROOT/test-results/acceptance-$RUN"
mkdir -p "$OUT"
umask 077
FIXTURE_FILE="$(mktemp "$ROOT/test-results/.acceptance-fixture.XXXXXX.json")"
ENV_FILE=""
OWN_ENV=no
FIXTURES=no
dc() { docker compose --env-file "$ENV_FILE" -p "$PROJECT" "$@"; }
cleanup() {
  local exit_status=$?
  set +e
  if [ "$FIXTURES" = yes ]; then
    dc run --rm -e ASCLA_ACCEPTANCE_ENV=qa -e ASCLA_ACCEPTANCE_RUN="$RUN" -e ASCLA_FIXTURE_ACTION=cleanup cli wp eval-file /opt/ascla-tests/acceptance-fixture.php >"$OUT/cleanup.log" 2>&1
    if [ $? -ne 0 ]; then echo "ERROR: revisar limpieza de fixtures en $OUT/cleanup.log"; exit_status=1; fi
  fi
  rm -f "$FIXTURE_FILE"
  if [ "$MODE" = ephemeral ] && [ -n "${PROJECT:-}" ]; then dc down -v --remove-orphans >>"$OUT/cleanup.log" 2>&1; fi
  if [ "$OWN_ENV" = yes ]; then rm -f "$ENV_FILE"; fi
  if [ -f "$OUT/index.html" ]; then echo "Reportes y videos: $OUT/index.html"; else echo "Diagnóstico de preparación: $OUT"; fi
  exit "$exit_status"
}
trap cleanup EXIT
case "$MODE" in
  ephemeral)
    PROJECT="ascla_acceptance_qa_${RUN//[^a-zA-Z0-9]/_}"
    PROJECT="${PROJECT,,}"
    ENV_FILE="$(mktemp "$ROOT/.env.acceptance.XXXXXX")"; OWN_ENV=yes
    command -v openssl >/dev/null
    secret() { openssl rand -hex 24; }
    cat >"$ENV_FILE" <<EOF_ENV
ASCLA_DB_NAME=wordpress
ASCLA_DB_USER=wordpress
ASCLA_DB_PASSWORD=$(secret)
ASCLA_DB_ROOT_PASSWORD=$(secret)
ASCLA_ADMIN_PASSWORD=$(secret)
ASCLA_DEMO_PASSWORD=$(secret)
ASCLA_PROFILE_DEMO_PASSWORD=$(secret)
ASCLA_SITE_URL=http://${PROJECT}-wordpress
ASCLA_ADMIN_USER=ascla.admin
ASCLA_ADMIN_EMAIL=admin@example.invalid
ASCLA_WP_ENVIRONMENT_TYPE=local
ASCLA_LOCAL_MAIL_CAPTURE=true
ASCLA_EXTERNAL_SECRETS_ONLY=0
ASCLA_SEED_DATA=false
EOF_ENV
    docker network inspect proxy_net >/dev/null 2>&1 || docker network create proxy_net >/dev/null
    dc up -d --build db mailpit wordpress
    ready=no
    for i in $(seq 1 24); do
      if dc run --rm cli sh /opt/ascla-scripts/init-wordpress.sh >"$OUT/setup.log" 2>&1; then ready=yes; break; fi
      sleep 5
    done
    if [ "$ready" != yes ]; then echo "WordPress no se pudo inicializar: $OUT/setup.log"; exit 1; fi
    ;;
  deployed)
    PROJECT="${ASCLA_TEST_PROJECT:?Indica el proyecto Docker de QA con ASCLA_TEST_PROJECT}"
    ENV_FILE="${ASCLA_TEST_ENV_FILE:?Indica el archivo privado de QA con ASCLA_TEST_ENV_FILE}"
    if [[ ! "$PROJECT" =~ ^ascla[_-].*qa ]] || [ ! -f "$ENV_FILE" ]; then echo 'Proyecto o archivo QA no válido.'; exit 1; fi
    EXPECTED_VERSION="$(sed -n 's/^ \* Version: *\([0-9.]*\).*/\1/p' wp-content/plugins/ascla-core/ascla-core.php)"
    test -n "$EXPECTED_VERSION"
    dc exec -T -e ASCLA_EXPECTED_VERSION="$EXPECTED_VERSION" wordpress php -r 'require "/var/www/html/wp-load.php"; if (!defined("ASCLA_VERSION") || ASCLA_VERSION!==getenv("ASCLA_EXPECTED_VERSION")) exit(1);' || { echo "QA debe tener activo ASCLA Core $EXPECTED_VERSION."; exit 1; }
    ;;
  remote)
    INPUT_FIXTURE="${ASCLA_TEST_FIXTURE:?Indica el JSON privado descargado desde Herramientas > ASCLA QA}"
    if [ ! -f "$INPUT_FIXTURE" ]; then echo 'No se encontró el archivo privado de acceso QA.'; exit 1; fi
    cp "$INPUT_FIXTURE" "$FIXTURE_FILE"
    ;;
  *) echo 'ASCLA_ACCEPTANCE_MODE debe ser ephemeral, deployed o remote.'; exit 1;;
esac
TOOL_NETWORK=bridge
if [ "$MODE" != remote ]; then
  FIXTURES=yes
  TOOL_NETWORK=proxy_net
  dc run --rm -e ASCLA_ACCEPTANCE_ENV=qa -e ASCLA_ACCEPTANCE_RUN="$RUN" cli wp eval-file /opt/ascla-tests/acceptance-fixture.php >"$FIXTURE_FILE"
fi
IMAGE="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.62.1-noble}"
# The Node tools remain outside WordPress; the source mount is read-only.
docker run --rm --ipc=host --network "$TOOL_NETWORK" \
  -e ASCLA_ACCEPTANCE_MODE="$MODE" \
  -e ASCLA_TEST_SITE_URL="${ASCLA_TEST_SITE_URL:-https://wordpress.ingsoftware.lat/}" \
  -e ASCLA_ACCEPTANCE_FIXTURE="/work/test-results/$(basename "$FIXTURE_FILE")" \
  -e ASCLA_ACCEPTANCE_OUTPUT="/work/test-results/$(basename "$OUT")" \
  -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  -v "$ROOT:/work:ro" -v "$ROOT/test-results:/work/test-results" \
  -v ascla_acceptance_node:/tools -w /work "$IMAGE" bash -lc '
    set -e
    mkdir -p /tools/browser /tools/bruno
    cp /work/package.json /work/pnpm-lock.yaml /tools/browser/
    cd /tools/browser
    corepack pnpm install --frozen-lockfile --ignore-scripts > /tmp/acceptance-browser-install.log 2>&1
    cd /tools/bruno
    if [ ! -f node_modules/@usebruno/cli/package.json ] || [ "$(node -p "require(\"./node_modules/@usebruno/cli/package.json\").version")" != 4.2.1 ]; then
      npm install --no-audit --no-fund @usebruno/cli@4.2.1 > /tmp/acceptance-bruno-install.log 2>&1
    fi
    export NODE_PATH=/tools/browser/node_modules
    export BRU_BIN=/tools/bruno/node_modules/.bin/bru
    cd /work
    node tests/acceptance-runner.cjs
  '
