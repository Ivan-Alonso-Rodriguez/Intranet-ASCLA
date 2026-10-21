#!/usr/bin/env bash
# Real DAST against an owned, disposable WordPress only. No remote active mode.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
for tool in docker openssl python3 flock; do command -v "$tool" >/dev/null || { echo "Falta $tool"; exit 3; }; done
docker info >/dev/null
mkdir -p "$ROOT/test-results"
exec 9>"$ROOT/test-results/.security.lock"
flock -n 9 || { echo 'Ya hay una ejecución de seguridad en curso.'; exit 3; }
OUT="$ROOT/test-results/security-latest"
mkdir -p "$OUT"
umask 077
RUN="$(date -u +%Y%m%d%H%M%S)-$$"
PROJECT="ascla_zap109_$RUN"
TLS_HOST="${PROJECT//_/-}.test"
NETWORK="${PROJECT}_scan"
ENV_FILE="$OUT/private.env"
ZAP_IMAGE='ghcr.io/zaproxy/zaproxy:stable@sha256:5ccfff9a411743ab2615136a2edd7416bec9ce41572fa94e935df38c14adb2b6'
BROWSER_IMAGE='mcr.microsoft.com/playwright:v1.62.1-noble'
dc(){ docker compose -f "$ROOT/compose.yaml" -f "$OUT/compose-security.yaml" --env-file "$ENV_FILE" -p "$PROJECT" "$@"; }
cleanup(){
  local status=$?
  trap - EXIT INT TERM
  set +e
  if [ ! -f "$OUT/index.html" ];then python3 "$ROOT/scripts/security-report.py" "$OUT" "$ROOT" >"$OUT/report.log" 2>&1;status=3;fi
  for kind in frontend backend; do docker rm -f "${PROJECT}_${kind}" >/dev/null 2>&1; done
  dc down -v --remove-orphans >>"$OUT/cleanup.log" 2>&1
  if [ $? -ne 0 ]; then status=3; echo 'ERROR: revisar cleanup.log'; fi
  if docker network inspect "$NETWORK" >/dev/null 2>&1;then docker network rm "$NETWORK" >>"$OUT/cleanup.log" 2>&1;fi
  rm -f "$ENV_FILE" "$OUT/private-actors.json" "$OUT/private-session.json" "$OUT/private-key.pem"
  echo "Reportes: test-results/security-latest/index.html (salida $status)"
  exit "$status"
}
# Clear only known generated reports. Never recursively delete a supplied path.
python3 - "$OUT" <<'PY'
import sys
from pathlib import Path
out=Path(sys.argv[1])
for pattern in ['*-zap.html','*-zap.json','*-result.json','*-console.log','*-engine.log','browser-security.json','browser.log','csp-validation.json','index.html','security-summary.json','cleanup.log']:
    for file in out.glob(pattern):
        if file.is_file():file.unlink()
PY
secret(){ openssl rand -hex 24; }
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -keyout "$OUT/private-key.pem" -out "$OUT/qa-cert.pem" -subj "/CN=$TLS_HOST" -addext "subjectAltName=DNS:$TLS_HOST" >"$OUT/certificate.log" 2>&1
cat >"$OUT/nginx.conf" <<EOF_NGINX
events {}
http {
  server_tokens off;
  server {
    listen 443 ssl;
    server_name $TLS_HOST;
    ssl_certificate /tls/qa-cert.pem;
    ssl_certificate_key /tls/private-key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    proxy_hide_header Strict-Transport-Security;
    add_header Strict-Transport-Security "max-age=31536000" always;
    client_max_body_size 64m;
    location / {
      proxy_pass http://wordpress:80;
      proxy_set_header Host \$host;
      proxy_set_header X-Forwarded-Proto https;
      proxy_set_header X-Forwarded-For \$remote_addr;
    }
  }
}
EOF_NGINX
cat >"$OUT/compose-security.yaml" <<EOF_COMPOSE
services:
  tls:
    image: nginx:stable-alpine
    volumes:
      - $OUT/nginx.conf:/etc/nginx/nginx.conf:ro
      - $OUT/qa-cert.pem:/tls/qa-cert.pem:ro
      - $OUT/private-key.pem:/tls/private-key.pem:ro
    depends_on: [wordpress]
    networks:
      default: {}
      proxy_net:
        aliases: [$TLS_HOST]
EOF_COMPOSE
cat >"$ENV_FILE" <<EOF_ENV
ASCLA_DB_HOST=db
ASCLA_DB_NAME=wordpress
ASCLA_DB_USER=wordpress
ASCLA_DB_PASSWORD=$(secret)
ASCLA_DB_ROOT_PASSWORD=$(secret)
ASCLA_ADMIN_PASSWORD=$(secret)
ASCLA_DEMO_PASSWORD=$(secret)
ASCLA_PROFILE_DEMO_PASSWORD=$(secret)
ASCLA_SITE_URL=https://$TLS_HOST
ASCLA_ADMIN_USER=ascla.admin
ASCLA_ADMIN_EMAIL=admin@example.invalid
ASCLA_WP_ENVIRONMENT_TYPE=local
ASCLA_LOCAL_MAIL_CAPTURE=true
ASCLA_EXTERNAL_SECRETS_ONLY=0
ASCLA_SEED_DATA=true
EOF_ENV
trap cleanup EXIT
trap 'exit 3' INT TERM
docker network inspect proxy_net >/dev/null 2>&1 || docker network create proxy_net >/dev/null
docker image inspect "$ZAP_IMAGE" >/dev/null 2>&1 || docker pull "$ZAP_IMAGE"
dc up -d db mailpit wordpress tls >"$OUT/setup.log" 2>&1
ready=no
for i in $(seq 1 24); do
  if dc run --rm cli sh /opt/ascla-scripts/init-wordpress.sh >>"$OUT/setup.log" 2>&1; then ready=yes;break;fi
  sleep 3
done
test "$ready" = yes || { echo 'ERROR: WordPress no inició; revisar setup.log'; exit 3; }
dc run --rm cli wp option update WPLANG es_ES >>"$OUT/setup.log" 2>&1
dc run --rm cli wp ascla seed >>"$OUT/setup.log" 2>&1
dc exec -T wordpress php /opt/ascla-tests/admin-navigation-fixture.php >"$OUT/private-actors.json"
python3 - "$ROOT" "$OUT" "$ZAP_IMAGE" <<'PY'
import hashlib,json,subprocess,sys
from pathlib import Path
root,out=map(Path,sys.argv[1:3]);paths=[]
for directory in ['wp-content/plugins/ascla-core','config','security']:
    paths.extend(p for p in (root/directory).rglob('*') if p.is_file())
paths.extend([root/'tests/zap-scan.py',root/'tests/security-openapi.py',root/'tests/security-browser.cjs',root/'scripts/check-security-headers.cjs'])
hashes={p.relative_to(root).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(paths)}
data={'source_sha256':hashlib.sha256(json.dumps(hashes,sort_keys=True).encode()).hexdigest(),'files':hashes,'zap_image':sys.argv[3],'zap_image_digest':subprocess.check_output(['docker','image','inspect',sys.argv[3],'--format','{{json .RepoDigests}}'],text=True).strip()}
(out/'source-manifest.json').write_text(json.dumps(data,indent=2)+'\n')
PY
# Browser packages are locked by pnpm-lock.yaml; persistent tools cache contains no sessions.
docker run --rm -v "$ROOT:/work:ro" -v ascla_acceptance_node:/tools -w /tools "$BROWSER_IMAGE" sh -c 'set -e;mkdir -p /tools/browser;cp /work/package.json /work/pnpm-lock.yaml /tools/browser/;cd /tools/browser;corepack pnpm install --frozen-lockfile --ignore-scripts' >"$OUT/browser-install.log" 2>&1
browser(){ docker run --rm --ipc=host --network proxy_net -e ASCLA_E2E_EPHEMERAL=1 -e ASCLA_SECURITY_OUT=/results -e NODE_PATH=/tools/browser/node_modules -v "$ROOT:/work:ro" -v "$OUT:/results" -v ascla_acceptance_node:/tools:ro -w /work "$BROWSER_IMAGE" node "$@"; }
reset_auth(){ dc exec -T -e ASCLA_E2E_EPHEMERAL=1 wordpress php /opt/ascla-tests/security-reset-fixture.php <"$OUT/private-actors.json";browser tests/security-auth.cjs; }
if ! browser tests/security-browser.cjs >"$OUT/browser.log" 2>&1; then cat "$OUT/browser.log";exit 3;fi
docker run --rm --network proxy_net -e NODE_EXTRA_CA_CERTS=/results/qa-cert.pem -v "$ROOT:/work:ro" -v "$OUT:/results" -w /work "$BROWSER_IMAGE" node scripts/check-security-headers.cjs "https://$TLS_HOST/login/" /results/csp-validation.json >"$OUT/csp.log" 2>&1 || true
cd "$ROOT"
python3 "$ROOT/tests/security-openapi.py" "$OUT"
docker network create --internal "$NETWORK" >/dev/null
docker network connect --alias "$TLS_HOST" "$NETWORK" "$(dc ps -q tls)"
for kind in frontend backend; do
  # Brute-force rules can correctly lock the shared QA IP. Reset only this owned
  # fixture's counters BETWEEN phases; never disable/reset protection in a scan.
  reset_auth >>"$OUT/browser.log" 2>&1
  echo "ZAP $kind: spiders/importación y escaneo activo (puede tardar varios minutos)."
  set +e
  docker run --rm --name "${PROJECT}_${kind}" --no-healthcheck --memory=6g --cpus=4 --pids-limit=4096 --network "$NETWORK" -e ASCLA_E2E_EPHEMERAL=1 -e JAVA_TOOL_OPTIONS=-Xmx1024m -v "$ROOT:/work:ro" -v "$OUT:/zap/wrk:rw" --entrypoint python "$ZAP_IMAGE" /work/tests/zap-scan.py "$kind" >"$OUT/$kind-console.log" 2>&1
  status=$?
  set -e
  tail -n 6 "$OUT/$kind-console.log"
  echo "Salida nativa $kind: $status"
done
python3 "$ROOT/scripts/security-report.py" "$OUT" "$ROOT"
