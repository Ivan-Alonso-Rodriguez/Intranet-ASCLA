param([switch]$SkipBrowser)
$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
  New-Item -ItemType Directory -Path coverage,test-results -Force | Out-Null
  docker compose exec -T wordpress sh -c 'test -f /tmp/phpunit.phar || curl -fsSL https://phar.phpunit.de/phpunit-11.phar -o /tmp/phpunit.phar'
  docker compose exec -T wordpress sh -c 'php -m | grep -q pcov || (pecl install pcov && docker-php-ext-enable pcov)'
  docker compose exec -T wordpress php -d pcov.enabled=1 -d pcov.directory=/var/www/html/wp-content/plugins/ascla-core/src /tmp/phpunit.phar --bootstrap /opt/ascla-tests/bootstrap.php --coverage-filter /var/www/html/wp-content/plugins/ascla-core/src --coverage-clover /tmp/ascla-clover.xml --coverage-text=/tmp/ascla-coverage.txt --log-junit /tmp/ascla-junit.xml /opt/ascla-tests/php
  if ($LASTEXITCODE -ne 0) { throw 'PHPUnit failed' }
  docker compose cp wordpress:/tmp/ascla-clover.xml coverage/clover.xml
  docker compose cp wordpress:/tmp/ascla-coverage.txt coverage/php-summary.txt
  docker compose cp wordpress:/tmp/ascla-junit.xml coverage/junit.xml
  $coverageFile=Join-Path (Get-Location) 'coverage/clover.xml'
  $report=[IO.File]::ReadAllText($coverageFile).Replace('/var/www/html/wp-content/plugins/ascla-core/','wp-content/plugins/ascla-core/')
  [IO.File]::WriteAllText($coverageFile,$report)
  $junitFile=Join-Path (Get-Location) 'coverage/junit.xml'
  [IO.File]::WriteAllText($junitFile,[IO.File]::ReadAllText($junitFile).Replace('/opt/ascla-tests/','tests/'))
  if (!$SkipBrowser) { node tests/e2e.cjs; if($LASTEXITCODE -ne 0){throw 'E2E failed'} }
} finally { Pop-Location }
