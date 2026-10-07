#!/usr/bin/env bash
# Manual analysis: never commits, merges, pushes or deploys.
set -euo pipefail
set +x
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
branch="$(git branch --show-current)"
if [ "$#" -gt 0 ]; then branch="$1"; fi
case "$branch" in
  qa) project=ASCLA-QA ;;
  uat) project=ASCLA-UAT ;;
  *) echo 'ERROR: indica qa o uat.' >&2; exit 1 ;;
esac
if [ "$(git branch --show-current)" != "$branch" ]; then
  echo "ERROR: cambia primero a la rama $branch." >&2; exit 1
fi
if [ -n "$(git status --porcelain)" ]; then
  echo 'ERROR: hay cambios sin commit. Analiza una revisión reproducible.' >&2; exit 1
fi
head="$(git rev-parse HEAD)"
if [ ! -f coverage/source-revision ] || [ "$(cat coverage/source-revision)" != "$head" ]; then
  echo "ERROR: ejecuta BRANCH_NAME=$branch bash scripts/quality-ci.sh para esta revisión." >&2; exit 1
fi
for report in coverage/clover.xml coverage/junit.xml coverage/lcov.info; do
  if [ ! -s "$report" ]; then echo "ERROR: falta $report." >&2; exit 1; fi
done
if ! [[ -v SONAR_HOST_URL ]]; then SONAR_HOST_URL=https://sonarqube.ingsoftware.lat; fi
export SONAR_HOST_URL
url_pattern='^https?://[[:alnum:]_.-]+(:[0-9]+)?(/[^[:space:]()]*)?$'
if [[ ! "$SONAR_HOST_URL" =~ $url_pattern ]]; then
  echo 'ERROR: SONAR_HOST_URL debe ser una URL simple, sin formato Markdown.' >&2; exit 1
fi
trap 'unset SONAR_TOKEN' EXIT
if ! [[ -v SONAR_TOKEN ]]; then
  read -r -s -p "Token nuevo de $project: " SONAR_TOKEN
  printf '\n'
fi
if [ -z "$SONAR_TOKEN" ]; then echo 'ERROR: el token no puede estar vacío.' >&2; exit 1; fi
export SONAR_TOKEN
docker run --rm \
  -e SONAR_HOST_URL -e SONAR_TOKEN \
  -v "$ROOT:/usr/src" -w /usr/src \
  sonarsource/sonar-scanner-cli \
  "-Dsonar.projectKey=$project" \
  "-Dsonar.projectName=$project" \
  "-Dsonar.scm.revision=$head" \
  -Dsonar.qualitygate.wait=true \
  -Dsonar.qualitygate.timeout=300
