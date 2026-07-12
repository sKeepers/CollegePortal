#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR=${APP_DIR:-/opt/college-portal}
COMPOSE_FILE="${APP_DIR}/installer/docker-compose.yml"
ENV_FILE="${APP_DIR}/.env"
backup=${1:-}
log() { printf '[INFO] %s\n' "$*"; }
fail() { printf '[ERROR] %s\n' "$*" >&2; exit 1; }
env_value() { grep -E "^$1=" "${ENV_FILE}" | head -1 | cut -d= -f2-; }
[[ -n "${backup}" ]] || fail "Usage: restore.sh /var/backups/college-portal/<timestamp>"
[[ -d "${backup}" ]] || fail "Backup directory not found: ${backup}"
[[ -f "${backup}/checksums.sha256" ]] || fail "Missing backup checksums."
(cd "${backup}" && sha256sum -c checksums.sha256)
printf 'Type RESTORE COLLEGEPORTAL to stop the app and restore this backup: '
read -r confirm
[[ "${confirm}" == "RESTORE COLLEGEPORTAL" ]] || fail "Restore cancelled."
cd "${APP_DIR}"
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" stop worker scheduler backend nginx frontend || true
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" up -d postgres
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T postgres psql \
  -v ON_ERROR_STOP=1 \
  -U "$(env_value POSTGRES_USER)" "$(env_value POSTGRES_DB)" \
  -c 'DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;'
cat "${backup}/database.sql" | docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T postgres psql \
  -v ON_ERROR_STOP=1 \
  -U "$(env_value POSTGRES_USER)" "$(env_value POSTGRES_DB)"
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" up -d backend
cat "${backup}/storage.tar.gz" | docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend tar -xzf - -C /var/www/html
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" up -d
"${APP_DIR}/installer/check.sh"
log "Restore completed from ${backup}."
