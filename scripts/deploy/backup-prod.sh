#!/usr/bin/env bash
set -euo pipefail

PROD_DIR="/home/andale/college_portal"
BACKUP_ROOT="/srv/backups/college-portal"
PROD_DB_CONTAINER="college_portal_postgres"
PROD_DB_NAME="college_portal"
PROD_DB_USER="college_user"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$BACKUP_ROOT/$TIMESTAMP"

log() {
  printf '[backup-prod] %s\n' "$1"
}

fail() {
  printf '[backup-prod] ERROR: %s\n' "$1" >&2
  exit 1
}

require_command() {
  local command_name="$1"
  command -v "$command_name" >/dev/null 2>&1 || fail "Command not found: $command_name"
}

[ -d "$PROD_DIR" ] || fail "PROD directory not found: $PROD_DIR"
require_command docker
require_command tar

status="$(docker inspect -f '{{.State.Status}}' "$PROD_DB_CONTAINER" 2>/dev/null || true)"
[ "$status" = "running" ] || fail "PROD database container is not running: $PROD_DB_CONTAINER"

log "Creating backup directory: $BACKUP_DIR"
sudo mkdir -p "$BACKUP_DIR"
sudo chown "$(id -u):$(id -g)" "$BACKUP_DIR"

log "Creating PROD files archive"
tar \
  --exclude='./frontend/node_modules' \
  --exclude='./backend/vendor' \
  --exclude='./tmp' \
  -czf "$BACKUP_DIR/prod-files.tgz" \
  -C "$PROD_DIR" .

log "Creating PROD PostgreSQL dump"
docker exec -t "$PROD_DB_CONTAINER" pg_dump -U "$PROD_DB_USER" "$PROD_DB_NAME" > "$BACKUP_DIR/prod-db.sql"

cat > "$BACKUP_DIR/backup-info.txt" <<INFO
created_at=$TIMESTAMP
prod_dir=$PROD_DIR
prod_db_container=$PROD_DB_CONTAINER
prod_db_name=$PROD_DB_NAME
prod_db_user=$PROD_DB_USER
INFO

log "Backup complete: $BACKUP_DIR"
log "No PROD data was deleted"
