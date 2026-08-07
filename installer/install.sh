#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR=${APP_DIR:-/opt/college-portal}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/college-portal}
DRY_RUN=${DRY_RUN:-0}
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
RELEASE_ROOT=$(cd "${SCRIPT_DIR}/.." && pwd)
COMPOSE_FILE="${APP_DIR}/installer/docker-compose.yml"
ENV_FILE="${APP_DIR}/.env"

log() { printf '[INFO] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*" >&2; }
fail() { printf '[ERROR] %s\n' "$*" >&2; exit 1; }
run() { if [[ "${DRY_RUN}" == "1" ]]; then printf '[DRY-RUN] %s\n' "$*"; else "$@"; fi; }
ask() { local prompt=$1 default=${2:-} answer; read -r -p "${prompt}${default:+ [${default}]}: " answer; printf '%s' "${answer:-$default}"; }
secret() { local prompt=$1 value; read -r -s -p "${prompt}: " value; printf '\n' >&2; printf '%s' "$value"; }
random_secret() { openssl rand -base64 32 | tr -d '\n'; }

require_root() {
  if [[ "${DRY_RUN}" == "1" ]]; then
    warn "DRY_RUN=1: root check is skipped; real installation still requires root."
    return
  fi
  [[ "${EUID}" -eq 0 ]] || fail "Run install.sh as root on the target VM."
}

check_host() {
  source /etc/os-release || fail "Cannot read /etc/os-release."
  [[ "${ID}" == "ubuntu" ]] || fail "Ubuntu Server is required. Found: ${ID}."
  case "${VERSION_ID}" in
    24.04) log "Ubuntu 24.04 LTS detected." ;;
    22.04) warn "Ubuntu 22.04 is compatible but 24.04 LTS is the target." ;;
    *) fail "Unsupported Ubuntu version: ${VERSION_ID}. Use 24.04 LTS." ;;
  esac
  [[ "$(uname -m)" == "x86_64" ]] || fail "amd64/x86_64 architecture is required."
  local mem_mb disk_gb
  mem_mb=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)
  disk_gb=$(df -BG / | awk 'NR==2 {gsub("G", "", $4); print $4}')
  (( mem_mb >= 4096 )) || warn "Less than 4 GB RAM detected (${mem_mb} MB). 8 GB is recommended."
  (( disk_gb >= 20 )) || warn "Less than 20 GB free disk detected (${disk_gb} GB). 60 GB is recommended."
}

check_port_free() {
  local port=$1
  if ss -ltn "sport = :${port}" | grep -q ":${port}"; then
    fail "Port ${port} is already in use. Choose another port or stop the conflicting service."
  fi
}

install_docker() {
  if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    log "Docker and Docker Compose are already installed."
    return
  fi
  log "Installing Docker Engine from Ubuntu repositories."
  run apt-get update
  local compose_package
  if apt-cache show docker-compose-plugin >/dev/null 2>&1; then
    compose_package=docker-compose-plugin
  elif apt-cache show docker-compose-v2 >/dev/null 2>&1; then
    compose_package=docker-compose-v2
  else
    compose_package=docker-compose
  fi
  log "Using ${compose_package} for Docker Compose."
  run apt-get install -y ca-certificates curl gnupg lsb-release docker.io "${compose_package}"
  run systemctl enable --now docker
}

copy_release() {
  if [[ -d "${APP_DIR}" && -f "${APP_DIR}/.env" ]]; then
    fail "${APP_DIR} already contains an installation. Use update.sh instead of install.sh."
  fi
  run mkdir -p "${APP_DIR}" "${BACKUP_DIR}" "${APP_DIR}/certs" "${APP_DIR}/acme"
  log "Copying release files to ${APP_DIR}."
  run rsync -a --delete \
    --exclude '.git' --exclude '.env' --exclude 'backend/.env' --exclude 'frontend/.env' \
    --exclude 'node_modules' --exclude 'vendor' --exclude 'frontend/dist' \
    --exclude 'storage/logs' --exclude 'tmp' --exclude 'logs' --exclude 'releases' \
    --exclude 'certs' --exclude 'acme' --exclude 'backups' \
    "${RELEASE_ROOT}/" "${APP_DIR}/"
}

write_env() {
  local domain https_mode college_name admin_email admin_password db_password timezone demo scheme session_secure
  domain=$(ask "Domain or IP" "$(hostname -I | awk '{print $1}')")
  https_mode=$(ask "HTTPS mode (http/existing-cert/letsencrypt/self-signed)" "http")
  college_name=$(ask "College short name" "CollegePortal")
  admin_email=$(ask "First admin email" "admin@college-portal.local")
  admin_password=$(secret "First admin password (min 10 chars)")
  [[ ${#admin_password} -ge 10 ]] || fail "Admin password must be at least 10 characters."
  db_password=$(secret "PostgreSQL password (leave empty to generate)")
  [[ -n "${db_password}" ]] || db_password=$(random_secret)
  timezone=$(ask "Timezone" "Europe/Moscow")
  demo=$(ask "Create demo data? Type yes to enable" "no")
  scheme="http"
  session_secure="false"
  if [[ "${https_mode}" != "http" ]]; then
    scheme="https"
    session_secure="true"
  fi
  if [[ "${https_mode}" == "letsencrypt" && "${domain}" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
    fail "Let's Encrypt cannot issue certificates for private IP addresses. Use an existing certificate or self-signed mode."
  fi
  if [[ "${https_mode}" == "existing-cert" ]]; then
    local cert_path key_path
    cert_path=$(ask "Path to fullchain.pem" "")
    key_path=$(ask "Path to privkey.pem" "")
    [[ -f "${cert_path}" && -f "${key_path}" ]] || fail "Certificate and private key files are required for existing-cert mode."
    run install -m 644 "${cert_path}" "${APP_DIR}/certs/fullchain.pem"
    run install -m 600 "${key_path}" "${APP_DIR}/certs/privkey.pem"
  elif [[ "${DRY_RUN}" != "1" ]]; then
    openssl req -x509 -nodes -newkey rsa:2048 -days 365 \
      -keyout "${APP_DIR}/certs/privkey.pem" \
      -out "${APP_DIR}/certs/fullchain.pem" \
      -subj "/CN=${domain}" >/dev/null 2>&1
    chmod 600 "${APP_DIR}/certs/privkey.pem"
  fi
  run cp "${APP_DIR}/installer/.env.example" "${ENV_FILE}"
  run chmod 600 "${ENV_FILE}"
  if [[ "${DRY_RUN}" != "1" ]]; then
    sed -i \
      -e "s#^APP_ENV=.*#APP_ENV=production#" \
      -e "s#^APP_DEBUG=.*#APP_DEBUG=false#" \
      -e "s#^APP_URL=.*#APP_URL=${scheme}://${domain}#" \
      -e "s#^APP_KEY=.*#APP_KEY=base64:$(random_secret)#" \
      -e "s#^APP_TIMEZONE=.*#APP_TIMEZONE=${timezone}#" \
      -e "s#^COLLEGE_SHORT_NAME=.*#COLLEGE_SHORT_NAME=${college_name}#" \
      -e "s#^DOMAIN_OR_IP=.*#DOMAIN_OR_IP=${domain}#" \
      -e "s#^HTTPS_MODE=.*#HTTPS_MODE=${https_mode}#" \
      -e "s#^SESSION_SECURE_COOKIE=.*#SESSION_SECURE_COOKIE=${session_secure}#" \
      -e "s#^POSTGRES_PASSWORD=.*#POSTGRES_PASSWORD=${db_password}#" \
      -e "s#^DB_PASSWORD=.*#DB_PASSWORD=${db_password}#" \
      "${ENV_FILE}"
    cat >> "${ENV_FILE}" <<ENVEOF
INSTALL_ADMIN_EMAIL=${admin_email}
INSTALL_ADMIN_PASSWORD=${admin_password}
INSTALL_WITH_DEMO_DATA=${demo}
ENVEOF
  fi
}

start_stack() {
  log "Starting CollegePortal containers."
  run docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" up -d --build
  if [[ "${DRY_RUN}" == "1" ]]; then return; fi
  log "Running migrations and required seeders."
  docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan migrate --force
  docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan db:seed --class=RoleSeeder --force || true
  docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan db:seed --class=ReferenceDataSeeder --force || true
  docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan install:create-admin \
    --email="$(grep '^INSTALL_ADMIN_EMAIL=' "${ENV_FILE}" | cut -d= -f2-)" \
    --password="$(grep '^INSTALL_ADMIN_PASSWORD=' "${ENV_FILE}" | cut -d= -f2-)"
  if [[ "$(grep '^INSTALL_WITH_DEMO_DATA=' "${ENV_FILE}" | cut -d= -f2-)" == "yes" ]]; then
    docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T backend php artisan db:seed --class=DemoDataSeeder --force || true
  fi
  sed -i '/^INSTALL_ADMIN_PASSWORD=/d;/^INSTALL_WITH_DEMO_DATA=/d' "${ENV_FILE}"
}

health_check() {
  if [[ "${DRY_RUN}" == "1" ]]; then
    log "DRY_RUN=1: health check is skipped because no runtime .env or containers are created."
    return
  fi
  local url
  url=$(grep '^APP_URL=' "${ENV_FILE}" | cut -d= -f2-)
  log "Checking ${url}/health/ready."
  curl -fsS "${url}/health/ready" >/dev/null || fail "Health check failed. Run installer/check.sh for details."
  log "CollegePortal is installed and healthy: ${url}"
}

main() {
  require_root
  check_host
  install_docker
  local http_port https_port
  http_port=$(ask "HTTP port" "80")
  https_port=$(ask "HTTPS port" "443")
  check_port_free "${http_port}"
  check_port_free "${https_port}"
  copy_release
  write_env
  if [[ "${DRY_RUN}" != "1" ]]; then
    sed -i -e "s#^HTTP_PORT=.*#HTTP_PORT=${http_port}#" -e "s#^HTTPS_PORT=.*#HTTPS_PORT=${https_port}#" "${ENV_FILE}"
  fi
  start_stack
  health_check
}

main "$@"
