#!/usr/bin/env bash
set -euo pipefail

HOST="${FIS_TEST_HOST:-10.0.3.1}"
PORT="${FIS_TEST_PORT:-8383}"
PATH_SUFFIX="${FIS_TEST_PATH:-/api/import/importservice.svc}"
TIMEOUT="${FIS_CHECK_TIMEOUT:-5}"
ENDPOINT="http://${HOST}:${PORT}${PATH_SUFFIX}"

section() { printf '\n== %s ==\n' "$1"; }

section "Host route"
if command -v ip >/dev/null 2>&1; then
  ip route get "$HOST" || true
else
  echo "ip command unavailable"
fi

section "TCP connect"
if command -v nc >/dev/null 2>&1; then
  nc -vz -w "$TIMEOUT" "$HOST" "$PORT" || true
else
  timeout "$TIMEOUT" bash -c "</dev/tcp/${HOST}/${PORT}" >/dev/null 2>&1 && echo "tcp reachable" || echo "tcp unreachable"
fi

section "HTTP endpoint"
if command -v curl >/dev/null 2>&1; then
  curl --connect-timeout "$TIMEOUT" --max-time $((TIMEOUT + 3)) -sS -o /tmp/fis-zkspd-check.out -w 'http_code=%{http_code} connect=%{time_connect} total=%{time_total}\n' "$ENDPOINT" || true
  if [ -s /tmp/fis-zkspd-check.out ]; then
    head -c 300 /tmp/fis-zkspd-check.out; echo
  fi
else
  echo "curl unavailable"
fi

section "Summary"
echo "endpoint=${ENDPOINT}"
echo "No credentials or SOAP payload were sent."
