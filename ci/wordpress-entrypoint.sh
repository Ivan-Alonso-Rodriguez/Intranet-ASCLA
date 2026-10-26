#!/usr/bin/env bash
set -Eeuo pipefail
if [ "${1:-}" != ascla-web ]; then
    exec docker-entrypoint.sh "$@"
fi
# Retain the official WordPress initialization/configuration and volume ownership.
docker-entrypoint.sh php-fpm -F &
php_pid=$!
httpd -DFOREGROUND &
apache_pid=$!
cleanup() {
    trap - EXIT INT TERM
    kill -TERM "$php_pid" "$apache_pid" 2>/dev/null || true
    wait "$php_pid" "$apache_pid" 2>/dev/null || true
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
set +e
wait -n "$php_pid" "$apache_pid"
status=$?
if [ "$status" = 0 ]; then status=1; fi
exit "$status"
