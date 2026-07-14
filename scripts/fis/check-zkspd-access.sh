#!/usr/bin/env bash
set -euo pipefail

HOST="${FIS_TEST_HOST:-10.0.3.1}"
PORT="${FIS_TEST_PORT:-8383}"
TIMEOUT="${FIS_CHECK_TIMEOUT:-5}"

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

section "Summary"
echo "target=${HOST}:${PORT}"
echo "TCP-only diagnostic: no HTTP request, credentials, SOAP payload or response body is used."
