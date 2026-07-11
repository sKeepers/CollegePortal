#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR=${APP_DIR:-/opt/college-portal}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/college-portal}
COMPOSE_FILE="${APP_DIR}/installer/docker-compose.yml"
ENV_FILE="${APP_DIR}/.env"
ts=$(date +%Y%m%d-%H%M%S)
out="${BACKUP_DIR}/${ts}"
log() { printf '[INFO] %s\n' "$*"; }
fail() { printf '[ERROR] %s\n' "$*" >&2; exit 1; }
env_value() { grep -E "^$1=" "${ENV_FILE}" | head -1 | cut -d= -f2-; }
[[ -f "${ENV_FILE}" ]] || fail "Missing ${ENV_FILE}"
mkdir -p "${out}"
chmod 700 "${out}"
cd "${APP_DIR}"
log "Creating PostgreSQL dump."
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T postgres pg_dump -U "$(env_value POSTGRES_USER)" "$(env_value POSTGRES_DB)" > "${out}/database.sql"
log "Archiving backend storage volume and protected config."
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend tar -czf - -C /var/www/html storage > "${out}/storage.tar.gz"
install -m 600 "${ENV_FILE}" "${out}/env.protected"
version=$(cat "${APP_DIR}/installer/VERSION" 2>/dev/null | tr '\n' ';')
sha256sum "${out}/database.sql" "${out}/storage.tar.gz" "${out}/env.protected" > "${out}/checksums.sha256"
cat > "${out}/manifest.json" <<MANIFEST
{
  "created_at": "$(date -Is)",
  "version": "${version}",
  "app_dir": "${APP_DIR}",
  "files": ["database.sql", "storage.tar.gz", "env.protected", "checksums.sha256"]
}
MANIFEST
chmod -R go-rwx "${out}"
log "Backup created: ${out}"
