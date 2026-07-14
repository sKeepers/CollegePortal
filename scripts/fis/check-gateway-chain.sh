#!/usr/bin/env bash
set -euo pipefail

GATEWAY_HOST="${FIS_GATEWAY_HOST:-192.168.34.223}"
GATEWAY_PORT="${FIS_GATEWAY_PORT:-8099}"
FIS_TEST_HOST="${FIS_TEST_HOST:-10.0.3.1}"
FIS_TEST_PORT="${FIS_TEST_PORT:-8383}"
TIMEOUT="${FIS_CHECK_TIMEOUT:-5}"
BASE_URL="http://${GATEWAY_HOST}:${GATEWAY_PORT}"

section() { printf '\n== %s ==\n' "$1"; }
tcp_probe() {
  local host="$1" port="$2"
  if command -v nc >/dev/null 2>&1; then
    nc -vz -w "$TIMEOUT" "$host" "$port" 2>&1 || true
  else
    timeout "$TIMEOUT" bash -c "</dev/tcp/${host}/${port}" >/dev/null 2>&1 && echo "tcp reachable" || echo "tcp unreachable"
  fi
}
http_probe() {
  local path="$1"
  curl --connect-timeout "$TIMEOUT" --max-time $((TIMEOUT + 2)) --max-redirs 0 \
    --silent --show-error --output /dev/null \
    --write-out "${path} http=%{http_code} connect=%{time_connect} total=%{time_total}\n" \
    "${BASE_URL}${path}" || true
}

section "Portal host"
hostname

section "Gateway ICMP"
ping -c 3 -W "$TIMEOUT" "$GATEWAY_HOST" || true

section "Gateway TCP"
tcp_probe "$GATEWAY_HOST" "$GATEWAY_PORT"

section "Gateway public endpoints"
for path in /health /version /adapters; do
  http_probe "$path"
done

section "Gateway protected FIS adapter"
http_probe /adapters/fis/health
echo "HTTP 401/403 means the protected endpoint is reachable but requires signed authentication."

section "Direct DEV to FIS TEST TCP"
tcp_probe "$FIS_TEST_HOST" "$FIS_TEST_PORT"

section "Safety"
echo "No production endpoint, credential, SOAP request or response body was used."
