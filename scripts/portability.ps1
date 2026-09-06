$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
  docker compose -f compose.portability.yaml up -d
  if($LASTEXITCODE -ne 0){throw 'Portability environment failed'}
  docker compose -f compose.portability.yaml run --rm cli sh -c 'if ! wp core is-installed --quiet; then wp core install --url=http://localhost:8089 --title="ASCLA ZIP Test" --admin_user=ascla.admin --admin_email=admin@example.invalid --admin_password="$ASCLA_ADMIN_PASSWORD" --skip-email; fi'
  if($LASTEXITCODE -ne 0){throw 'Fresh WordPress install failed'}
  docker compose -f compose.portability.yaml run --rm cli wp eval-file /opt/ascla-tests/portability.php before
  docker compose -f compose.portability.yaml run --rm cli wp plugin install /dist/ascla-core.zip --activate --force
  if($LASTEXITCODE -ne 0){throw 'ZIP installation failed'}
  docker compose -f compose.portability.yaml run --rm cli wp eval-file /opt/ascla-tests/portability.php after > test-results/portability.json
  if($LASTEXITCODE -ne 0){throw 'Portability assertions failed'}
  docker compose -f compose.portability.yaml run --rm cli wp ascla seed
  if($LASTEXITCODE -ne 0){throw 'ZIP demo seed failed'}
} finally { Pop-Location }
