#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR=${APP_DIR:-/opt/college-portal}
COMPOSE_FILE="${APP_DIR}/installer/docker-compose.yml"
ENV_FILE="${APP_DIR}/.env"
fail() { printf '[ERROR] %s\n' "$*" >&2; exit 1; }
printf 'This will stop containers and remove app files and Docker volumes under %s. Backups will not be deleted.\n' "${APP_DIR}"
printf 'Create backup before uninstall? [yes/no]: '
read -r backup
[[ "${backup}" == "yes" && -x "${APP_DIR}/installer/backup.sh" ]] && "${APP_DIR}/installer/backup.sh"
printf 'Type DELETE COLLEGEPORTAL to continue: '
read -r confirm
[[ "${confirm}" == "DELETE COLLEGEPORTAL" ]] || fail "Uninstall cancelled."
if [[ -f "${COMPOSE_FILE}" && -f "${ENV_FILE}" ]]; then
  cd "${APP_DIR}"
  docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" down --volumes
fi
rm -rf "${APP_DIR}"
printf 'CollegePortal files removed. Backups were preserved.\n'
