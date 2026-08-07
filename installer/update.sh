#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR=${APP_DIR:-/opt/college-portal}
RELEASE=${1:-}
COMPOSE_FILE="${APP_DIR}/installer/docker-compose.yml"
ENV_FILE="${APP_DIR}/.env"
log() { printf '[INFO] %s\n' "$*"; }
fail() { printf '[ERROR] %s\n' "$*" >&2; exit 1; }
read_version() { local file=$1; (source "${file}" && printf '%s' "${version:-unknown}"); }
[[ -n "${RELEASE}" ]] || fail "Usage: update.sh /path/to/college-portal-<version>.tar.gz"
[[ -f "${RELEASE}" ]] || fail "Release archive not found: ${RELEASE}"
[[ -f "${ENV_FILE}" ]] || fail "Existing installation not found at ${APP_DIR}"
work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
tar -xzf "${RELEASE}" -C "${work}"
current_version=$(read_version "${APP_DIR}/installer/VERSION")
release_version=$(read_version "${work}/installer/VERSION")
if [[ "${current_version}" == "${release_version}" && "${FORCE_SAME_VERSION_UPDATE:-0}" != "1" ]]; then
  fail "Refusing to update ${APP_DIR} from ${current_version} to the same version. Set FORCE_SAME_VERSION_UPDATE=1 only for explicit repair runs."
fi
backup_path=$("${APP_DIR}/installer/backup.sh" | awk '/Backup created:/ {print $NF}')
log "Backup before update: ${backup_path}"
cd "${APP_DIR}"
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan down || true
rsync -a --delete --exclude '.env' --exclude 'certs' --exclude 'acme' --exclude 'storage' --exclude 'backend/storage' "${work}/" "${APP_DIR}/"
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" up -d --build
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" up -d --force-recreate nginx
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan migrate --force
docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan up || true
for attempt in {1..12}; do
  if curl -fsS "$(grep '^APP_URL=' "${ENV_FILE}" | cut -d= -f2-)/health/ready" >/dev/null; then
    break
  fi
  sleep 5
done
if ! "${APP_DIR}/installer/check.sh"; then
  printf 'Health check failed. Type ROLLBACK to restore %s: ' "${backup_path}"
  read -r confirm
  [[ "${confirm}" == "ROLLBACK" ]] && "${APP_DIR}/installer/restore.sh" "${backup_path}"
  exit 1
fi
log "Update completed."
