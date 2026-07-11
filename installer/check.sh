#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR=${APP_DIR:-/opt/college-portal}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/college-portal}
COMPOSE_FILE="${APP_DIR}/installer/docker-compose.yml"
ENV_FILE="${APP_DIR}/.env"
status=0
ok() { printf 'OK      %s\n' "$*"; }
warn() { printf 'WARNING %s\n' "$*"; }
err() { printf 'ERROR   %s\n' "$*"; status=1; }
env_value() { grep -E "^$1=" "${ENV_FILE}" | head -1 | cut -d= -f2-; }
[[ -f "${ENV_FILE}" ]] || err "Missing ${ENV_FILE}"
[[ -f "${COMPOSE_FILE}" ]] || err "Missing ${COMPOSE_FILE}"
if [[ -f "${ENV_FILE}" && -f "${COMPOSE_FILE}" ]]; then
  cd "${APP_DIR}"
  docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" ps || err "docker compose ps failed"
  docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T postgres pg_isready -U "$(env_value POSTGRES_USER)" >/dev/null 2>&1 && ok "database accepts connections" || err "database check failed"
  docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend test -w storage && ok "backend storage writable" || err "storage is not writable"
  docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan queue:failed >/dev/null 2>&1 && ok "queue command available" || warn "queue command check failed"
  url=$(env_value APP_URL || true)
  [[ -n "${url}" ]] && curl -fsS "${url}/health/live" >/dev/null && ok "frontend/proxy live endpoint" || err "live endpoint failed"
  [[ -n "${url}" ]] && curl -fsS "${url}/health/ready" >/dev/null && ok "ready endpoint" || err "ready endpoint failed"
  [[ -n "${url}" ]] && curl -fsS "${url}/version.json" >/dev/null && ok "version.json available" || warn "version.json not available"
  if [[ "${url}" == https://* ]]; then
    host=$(echo "${url}" | sed -E 's#https://([^/:]+).*#\1#')
    echo | openssl s_client -connect "${host}:443" -servername "${host}" >/dev/null 2>&1 && ok "HTTPS certificate responds" || warn "HTTPS certificate check failed"
  fi
fi
free_gb=$(df -BG "${APP_DIR}" 2>/dev/null | awk 'NR==2 {gsub("G", "", $4); print $4}' || echo 0)
(( free_gb >= 10 )) && ok "disk free ${free_gb}G" || warn "low disk free ${free_gb}G"
latest_backup=$(find "${BACKUP_DIR}" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sort | tail -1 || true)
[[ -n "${latest_backup}" ]] && ok "latest backup ${latest_backup}" || warn "no backups found"
exit "${status}"
