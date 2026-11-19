param([switch]$Visible, [string]$Fixture)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$accessPath = if ($Fixture) { (Resolve-Path -LiteralPath $Fixture).Path } else { Join-Path $projectRoot 'test-results/playwright-usuarios.json' }
if (!(Test-Path -LiteralPath $accessPath)) { throw 'Falta el acceso privado preparado en test-results/playwright-usuarios.json.' }
$accessConfig = Get-Content -LiteralPath $accessPath -Raw | ConvertFrom-Json
$nodeExecutable = $accessConfig.runtime.node
if (!$nodeExecutable -or !(Test-Path -LiteralPath $nodeExecutable)) {
  $nodeExecutable = (Get-Command node -ErrorAction Stop).Source
}
$previousNodePath = $env:NODE_PATH
$previousBrowserPath = $env:PLAYWRIGHT_BROWSERS_PATH
try {
  if ($accessConfig.runtime.modules) { $env:NODE_PATH = $accessConfig.runtime.modules }
  $preparedBrowserPath = Join-Path $projectRoot 'test-results/playwright-browsers'
  if (!$previousBrowserPath -and (Test-Path -LiteralPath $preparedBrowserPath)) {
    $env:PLAYWRIGHT_BROWSERS_PATH = $preparedBrowserPath
  }
  $runArguments = @((Join-Path $PSScriptRoot 'run-playwright-test-users.cjs'), '--fixture', $accessPath)
  if ($Visible) { $runArguments += '--headed' }
  & $nodeExecutable @runArguments
  if ($LASTEXITCODE -ne 0) { throw 'Playwright registró un fallo. Revisa el reporte indicado en la salida.' }
} finally {
  $env:NODE_PATH = $previousNodePath
  $env:PLAYWRIGHT_BROWSERS_PATH = $previousBrowserPath
}
