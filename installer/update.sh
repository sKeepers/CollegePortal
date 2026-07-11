#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR=${APP_DIR:-/opt/college-portal}
RELEASE=${1:-}
COMPOSE_FILE="${APP_DIR}/installer/docker-compose.yml"
ENV_FILE="${APP_DIR}/.env"
log() { printf '[INFO] %s\n' "$*"; }
fail() { printf '[ERROR] %s\n' "$*" >&2; exit 1; }
[[ -n "${RELEASE}" ]] || fail "Usage: update.sh /path/to/college-portal-<version>.tar.gz"
[[ -f "${RELEASE}" ]] || fail "Release archive not found: ${RELEASE}"
[[ -f "${ENV_FILE}" ]] || fail "Existing installation not found at ${APP_DIR}"
backup_path=$("${APP_DIR}/installer/backup.sh" | awk '/Backup created:/ {print $3}')
log "Backup before update: ${backup_path}"
work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
tar -xzf "${RELEASE}" -C "${work}"
cd "${APP_DIR}"
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan down || true
rsync -a --delete --exclude '.env' --exclude 'certs' --exclude 'storage' --exclude 'backend/storage' "${work}/" "${APP_DIR}/"
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" up -d --build
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan migrate --force
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan up || true
if ! "${APP_DIR}/installer/check.sh"; then
  printf 'Health check failed. Type ROLLBACK to restore %s: ' "${backup_path}"
  read -r confirm
  [[ "${confirm}" == "ROLLBACK" ]] && "${APP_DIR}/installer/restore.sh" "${backup_path}"
  exit 1
fi
log "Update completed."
