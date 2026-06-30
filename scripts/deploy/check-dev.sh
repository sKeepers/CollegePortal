#!/usr/bin/env bash
set -euo pipefail

DEV_DIR="/srv/college-dev"
DEV_FRONTEND_URL="http://127.0.0.1:5174/dashboard"
DEV_API_URL="http://127.0.0.1:8001/api"
ADMIN_EMAIL="admin@college-portal.local"
ADMIN_PASSWORD="password"

log() {
  printf '[check-dev] %s\n' "$1"
}

fail() {
  printf '[check-dev] ERROR: %s\n' "$1" >&2
  exit 1
}

require_dir() {
  local path="$1"
  [ -d "$path" ] || fail "Directory not found: $path"
}

require_command() {
  local command_name="$1"
  command -v "$command_name" >/dev/null 2>&1 || fail "Command not found: $command_name"
}

check_container() {
  local name="$1"
  local status
  status="$(docker inspect -f '{{.State.Status}}' "$name" 2>/dev/null || true)"
  [ "$status" = "running" ] || fail "Container is not running: $name"
  log "Container running: $name"
}

http_status() {
  local url="$1"
  curl -s -o /tmp/college-dev-check.out -w '%{http_code}' "$url"
}

require_dir "$DEV_DIR"
require_command docker
require_command curl

cd "$DEV_DIR"

log "Checking DEV containers"
check_container college_dev_frontend
check_container college_dev_nginx
check_container college_dev_backend
check_container college_dev_postgres

log "Checking DEV frontend: $DEV_FRONTEND_URL"
frontend_code="$(http_status "$DEV_FRONTEND_URL")"
[ "$frontend_code" = "200" ] || fail "DEV frontend returned HTTP $frontend_code"
log "DEV frontend OK"

log "Checking DEV API login"
login_response="$(curl -s -w '\nHTTP:%{http_code}' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}" \
  "$DEV_API_URL/auth/login")"
login_code="$(printf '%s' "$login_response" | sed -n 's/^HTTP://p')"
login_body="$(printf '%s' "$login_response" | sed '/^HTTP:/d')"
[ "$login_code" = "200" ] || fail "DEV API login returned HTTP $login_code"
printf '%s' "$login_body" | grep -q '"token"' || fail "DEV API login response does not contain token"
log "DEV API login OK"

log "Running frontend build in DEV"
docker compose exec -T frontend npm run build
log "DEV build OK"

log "All DEV checks passed"
