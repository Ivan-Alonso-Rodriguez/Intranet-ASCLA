param([string]$Project='ascla-portability')
$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
  docker compose -f compose.portability.yaml --project-name $Project up -d
  if($LASTEXITCODE -ne 0){throw 'Portability environment failed'}
  docker compose -f compose.portability.yaml --project-name $Project run --rm cli sh /opt/ascla-tests/install-portability.sh
  if($LASTEXITCODE -ne 0){throw 'Fresh WordPress install failed'}
  docker compose -f compose.portability.yaml --project-name $Project run --rm cli wp eval-file /opt/ascla-tests/portability.php before
  docker compose -f compose.portability.yaml --project-name $Project run --rm cli wp plugin install /dist/ascla-core.zip --activate --force
  if($LASTEXITCODE -ne 0){throw 'ZIP installation failed'}
  $portabilityOutput = docker compose -f compose.portability.yaml --project-name $Project run --rm cli wp eval-file /opt/ascla-tests/portability.php after
  if($LASTEXITCODE -ne 0){throw 'Portability assertions failed'}
  [IO.File]::WriteAllLines((Join-Path (Get-Location) 'test-results/portability.json'), $portabilityOutput, (New-Object Text.UTF8Encoding $false))
  docker compose -f compose.portability.yaml --project-name $Project run --rm cli wp ascla seed
  if($LASTEXITCODE -ne 0){throw 'ZIP demo seed failed'}
} finally { Pop-Location }
